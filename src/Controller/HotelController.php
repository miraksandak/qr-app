<?php

namespace App\Controller;

use App\Entity\Hotel;
use App\Entity\HotelConfiguration;
use App\Repository\HotelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HotelController extends AbstractController
{
    private const ALLOWED_AUTH_MODES = ['roomSurname', 'accessCode'];
    private const ALLOWED_DEVICE_TYPES = ['android', 'ios', 'generic'];
    private const ALLOWED_SSID_USAGES = ['pms', 'ac', 'free'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(API_KEY)%')]
        private string $apiKey
    ) {
    }

    #[Route('/api/hotels/{externalHotelId}', name: 'api_hotel_get', methods: ['GET'])]
    public function getHotel(string $externalHotelId, Request $request, HotelRepository $repository): JsonResponse
    {
        $authorization = $this->authorize($request);
        if ($authorization !== null) {
            return $authorization;
        }

        try {
            $normalizedHotelId = $this->normalizeExternalHotelId($externalHotelId);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($normalizedHotelId === null) {
            return new JsonResponse(['error' => 'externalHotelId is required'], Response::HTTP_BAD_REQUEST);
        }

        $hotel = $repository->findOneByExternalHotelId($normalizedHotelId);
        if ($hotel === null) {
            return new JsonResponse(['error' => 'Hotel not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeHotel($hotel));
    }

    #[Route('/api/hotels/{externalHotelId}', name: 'api_hotel_upsert', methods: ['PUT'])]
    public function upsertHotel(string $externalHotelId, Request $request, HotelRepository $repository): JsonResponse
    {
        $authorization = $this->authorize($request);
        if ($authorization !== null) {
            return $authorization;
        }

        try {
            $normalizedHotelId = $this->normalizeExternalHotelId($externalHotelId);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($normalizedHotelId === null) {
            return new JsonResponse(['error' => 'externalHotelId is required'], Response::HTTP_BAD_REQUEST);
        }

        $rawContent = trim((string) $request->getContent());
        $rawContent = $rawContent === '' ? '{}' : $rawContent;

        try {
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload must be a JSON object'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $hotel = $repository->findOneByExternalHotelId($normalizedHotelId);
            $created = $hotel === null;

            if ($hotel === null) {
                $hotel = new Hotel($normalizedHotelId, $this->normalizeNullableString($payload['name'] ?? null));
                $configuration = new HotelConfiguration($hotel);
                $hotel->setConfiguration($configuration);

                $this->entityManager->persist($hotel);
                $this->entityManager->persist($configuration);
            } elseif (array_key_exists('name', $payload)) {
                $hotel->setName($this->normalizeNullableString($payload['name']));
            }

            if ($hotel->getConfiguration() === null) {
                $configuration = new HotelConfiguration($hotel);
                $hotel->setConfiguration($configuration);

                $this->entityManager->persist($configuration);
            }

            if (isset($payload['configuration'])) {
                if (!is_array($payload['configuration'])) {
                    return new JsonResponse(['error' => 'configuration must be an object'], Response::HTTP_BAD_REQUEST);
                }

                $this->applyConfigurationPatch($hotel->getConfiguration(), $payload['configuration']);
            }

            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(
            $this->serializeHotel($hotel),
            $created ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    private function authorize(Request $request): ?JsonResponse
    {
        $apiKey = (string) $request->headers->get('X-API-Key');
        if ($apiKey !== $this->apiKey) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function normalizeExternalHotelId(string $externalHotelId): ?string
    {
        $value = trim($externalHotelId);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > 128) {
            throw new \InvalidArgumentException('externalHotelId is too long');
        }

        return $value;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string value');
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function applyConfigurationPatch(HotelConfiguration $configuration, array $payload): void
    {
        if (array_key_exists('supportText', $payload)) {
            $configuration->setSupportText($this->normalizeNullableString($payload['supportText']));
        }

        if (array_key_exists('footerText', $payload)) {
            $configuration->setFooterText($this->normalizeNullableString($payload['footerText']));
        }

        if (array_key_exists('logoUrl', $payload)) {
            $configuration->setLogoUrl($this->normalizeNullableString($payload['logoUrl']));
        }

        if (array_key_exists('portalUrl', $payload)) {
            $configuration->setPortalUrl($this->normalizeNullableString($payload['portalUrl']));
        }

        if (array_key_exists('proxyApiBaseUrl', $payload)) {
            $configuration->setProxyApiBaseUrl($this->normalizeNullableString($payload['proxyApiBaseUrl']));
        }

        if (array_key_exists('datacenterId', $payload)) {
            $configuration->setDatacenterId($this->normalizeNullableString($payload['datacenterId']));
        }

        if (array_key_exists('primaryAuthMode', $payload)) {
            $configuration->setPrimaryAuthMode($this->normalizePrimaryAuthMode($payload['primaryAuthMode']));
        }

        if (array_key_exists('device', $payload)) {
            if (!is_array($payload['device'])) {
                throw new \InvalidArgumentException('device must be an object');
            }

            $this->applyDevicePatch($configuration, $payload['device']);
        }

        if (array_key_exists('ssids', $payload)) {
            $configuration->setSsids($this->normalizeSsids($payload['ssids']));
        }

        if (array_key_exists('upgrade', $payload)) {
            if (!is_array($payload['upgrade'])) {
                throw new \InvalidArgumentException('upgrade must be an object');
            }

            $this->applyUpgradePatch($configuration, $payload['upgrade']);
        }
    }

    private function applyDevicePatch(HotelConfiguration $configuration, array $payload): void
    {
        if (array_key_exists('available', $payload)) {
            $availableDevices = $this->normalizeAvailableDevices($payload['available']);
            $configuration->setAvailableDevices($availableDevices);

            if (!in_array($configuration->getDefaultDevice(), $availableDevices, true)) {
                $configuration->setDefaultDevice($availableDevices[0]);
            }
        }

        if (array_key_exists('default', $payload)) {
            $defaultDevice = $this->normalizeDeviceType($payload['default']);
            $availableDevices = $configuration->getAvailableDevices();

            if (!in_array($defaultDevice, $availableDevices, true)) {
                throw new \InvalidArgumentException('device.default must be one of the available devices');
            }

            $configuration->setDefaultDevice($defaultDevice);
        }
    }

    private function applyUpgradePatch(HotelConfiguration $configuration, array $payload): void
    {
        if (array_key_exists('enabled', $payload)) {
            if (!is_bool($payload['enabled'])) {
                throw new \InvalidArgumentException('upgrade.enabled must be a boolean');
            }

            $configuration->setUpgradeEnabled($payload['enabled']);
        }

        if (array_key_exists('url', $payload)) {
            $configuration->setUpgradeUrl($this->normalizeNullableString($payload['url']));
        }
    }

    private function normalizePrimaryAuthMode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_AUTH_MODES, true)) {
            throw new \InvalidArgumentException('primaryAuthMode must be one of: roomSurname, accessCode');
        }

        return $value;
    }

    private function normalizeDeviceType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_DEVICE_TYPES, true)) {
            throw new \InvalidArgumentException('device type must be one of: android, ios, generic');
        }

        return $value;
    }

    private function normalizeAvailableDevices(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('device.available must be an array');
        }

        $devices = [];
        foreach ($value as $device) {
            $devices[] = $this->normalizeDeviceType($device);
        }

        $devices = array_values(array_unique($devices));
        if ($devices === []) {
            throw new \InvalidArgumentException('device.available must not be empty');
        }

        return $devices;
    }

    private function normalizeSsids(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ssids must be an array');
        }

        $ssids = [];
        foreach ($value as $index => $ssid) {
            if (!is_array($ssid)) {
                throw new \InvalidArgumentException(sprintf('ssids[%d] must be an object', $index));
            }

            $name = $this->normalizeNullableString($ssid['name'] ?? null);
            if ($name === null) {
                throw new \InvalidArgumentException(sprintf('ssids[%d].name is required', $index));
            }

            $usage = $ssid['usage'] ?? null;
            if (!is_string($usage) || !in_array($usage, self::ALLOWED_SSID_USAGES, true)) {
                throw new \InvalidArgumentException(sprintf('ssids[%d].usage must be one of: pms, ac, free', $index));
            }

            $ssids[] = [
                'name' => $name,
                'usage' => $usage,
            ];
        }

        return $ssids;
    }

    private function serializeHotel(Hotel $hotel): array
    {
        $configuration = $hotel->getConfiguration();

        return [
            'hotel' => [
                'id' => $hotel->getId(),
                'externalHotelId' => $hotel->getExternalHotelId(),
                'name' => $hotel->getName(),
                'createdAt' => $hotel->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $hotel->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'configuration' => $configuration === null ? null : [
                'id' => $configuration->getId(),
                'supportText' => $configuration->getSupportText(),
                'footerText' => $configuration->getFooterText(),
                'logoUrl' => $configuration->getLogoUrl(),
                'portalUrl' => $configuration->getPortalUrl(),
                'proxyApiBaseUrl' => $configuration->getProxyApiBaseUrl(),
                'datacenterId' => $configuration->getDatacenterId(),
                'primaryAuthMode' => $configuration->getPrimaryAuthMode(),
                'device' => [
                    'default' => $configuration->getDefaultDevice(),
                    'available' => $configuration->getAvailableDevices(),
                ],
                'ssids' => $configuration->getSsids(),
                'upgrade' => [
                    'enabled' => $configuration->isUpgradeEnabled(),
                    'url' => $configuration->getUpgradeUrl(),
                ],
                'createdAt' => $configuration->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $configuration->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ];
    }
}
