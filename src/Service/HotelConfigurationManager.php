<?php

namespace App\Service;

use App\Connector\ExternalHotelAccess;
use App\Entity\Hotel;
use App\Entity\HotelConfiguration;
use App\Repository\HotelRepository;
use Doctrine\ORM\EntityManagerInterface;

class HotelConfigurationManager
{
    private const ALLOWED_AUTH_MODES = ['roomSurname', 'accessCode'];
    private const ALLOWED_DEVICE_TYPES = ['android', 'ios', 'generic'];
    private const ALLOWED_SSID_USAGES = ['pms', 'ac', 'free'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HotelRepository $hotelRepository
    ) {
    }

    /**
     * @param list<ExternalHotelAccess> $accessibleHotels
     */
    public function buildAccessibleHotelList(array $accessibleHotels): array
    {
        $hotelsByExternalId = [];
        foreach ($this->hotelRepository->findByExternalHotelIds(array_map(
            static fn (ExternalHotelAccess $hotel): string => $hotel->getExternalHotelId(),
            $accessibleHotels
        )) as $hotelEntity) {
            $hotelsByExternalId[$hotelEntity->getExternalHotelId()] = $hotelEntity;
        }

        $result = [];
        foreach ($accessibleHotels as $accessibleHotel) {
            $hotelEntity = $hotelsByExternalId[$accessibleHotel->getExternalHotelId()] ?? null;

            $result[] = [
                'externalHotelId' => $accessibleHotel->getExternalHotelId(),
                'name' => $hotelEntity?->getName() ?? $accessibleHotel->getName(),
                'externalName' => $accessibleHotel->getName(),
                'configurationExists' => $hotelEntity?->getConfiguration() !== null,
                'attributes' => $accessibleHotel->getAttributes(),
            ];
        }

        return $result;
    }

    public function buildHotelSnapshot(ExternalHotelAccess $accessibleHotel): array
    {
        $hotel = $this->hotelRepository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        if ($hotel !== null && $hotel->getConfiguration() !== null) {
            return $this->serializeHotel($hotel, true);
        }

        return [
            'hotel' => [
                'id' => $hotel?->getId(),
                'externalHotelId' => $accessibleHotel->getExternalHotelId(),
                'name' => $hotel?->getName() ?? $accessibleHotel->getName(),
                'createdAt' => $hotel?->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $hotel?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'configuration' => $this->serializeConfiguration($this->createDefaultConfigurationPreview()),
            'meta' => [
                'persisted' => false,
            ],
        ];
    }

    public function ensureHotelWithConfiguration(ExternalHotelAccess $accessibleHotel): Hotel
    {
        $hotel = $this->hotelRepository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        if ($hotel === null) {
            $hotel = new Hotel(
                $accessibleHotel->getExternalHotelId(),
                $accessibleHotel->getName() ?? 'Hotel Guest'
            );
            $this->entityManager->persist($hotel);
        } elseif ($hotel->getName() === null && $accessibleHotel->getName() !== null) {
            $hotel->setName($accessibleHotel->getName());
        }

        if ($hotel->getConfiguration() === null) {
            $configuration = new HotelConfiguration($hotel);
            $this->applyDefaultConfiguration($configuration);
            $hotel->setConfiguration($configuration);

            $this->entityManager->persist($configuration);
        }

        return $hotel;
    }

    public function updateHotelConfiguration(ExternalHotelAccess $accessibleHotel, array $payload): Hotel
    {
        $hotel = $this->ensureHotelWithConfiguration($accessibleHotel);

        if (array_key_exists('name', $payload)) {
            $hotel->setName(
                $this->normalizeNullableString($payload['name'])
                ?? $accessibleHotel->getName()
                ?? 'Hotel Guest'
            );
        }

        if (isset($payload['configuration'])) {
            if (!is_array($payload['configuration'])) {
                throw new \InvalidArgumentException('configuration must be an object');
            }

            $this->applyConfigurationPatch($hotel->getConfiguration(), $payload['configuration']);
        }

        return $hotel;
    }

    public function serializeHotel(Hotel $hotel, bool $persisted = true): array
    {
        return [
            'hotel' => [
                'id' => $hotel->getId(),
                'externalHotelId' => $hotel->getExternalHotelId(),
                'name' => $hotel->getName(),
                'createdAt' => $hotel->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $hotel->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'configuration' => $this->serializeConfiguration($hotel->getConfiguration()),
            'meta' => [
                'persisted' => $persisted,
            ],
        ];
    }

    public function buildManualHotelPayload(Hotel $hotel): array
    {
        $configuration = $hotel->getConfiguration();
        if ($configuration === null) {
            return [];
        }

        return [
            'hotel' => [
                'name' => $hotel->getName() ?? 'Hotel Guest',
                'supportText' => $configuration->getSupportText(),
                'footerText' => $configuration->getFooterText(),
                'logoUrl' => $configuration->getLogoUrl(),
                'portalUrl' => $configuration->getPortalUrl(),
                'ssids' => $configuration->getSsids(),
                'upgrade' => [
                    'enabled' => $configuration->isUpgradeEnabled(),
                    'url' => $configuration->getUpgradeUrl(),
                ],
            ],
            'device' => [
                'default' => $configuration->getDefaultDevice(),
                'available' => $configuration->getAvailableDevices(),
            ],
            'options' => [
                'mode' => $configuration->getPrimaryAuthMode(),
            ],
        ];
    }

    private function createDefaultConfigurationPreview(): HotelConfigurationPreview
    {
        return new HotelConfigurationPreview(
            'Need help? Contact reception.',
            null,
            '/media/mik-logo.png',
            null,
            null,
            null,
            'roomSurname',
            'generic',
            ['android', 'ios', 'generic'],
            [],
            false,
            null
        );
    }

    private function applyDefaultConfiguration(HotelConfiguration $configuration): void
    {
        $configuration->setSupportText('Need help? Contact reception.');
        $configuration->setLogoUrl('/media/mik-logo.png');
        $configuration->setFooterText(null);
        $configuration->setPortalUrl(null);
        $configuration->setProxyApiBaseUrl(null);
        $configuration->setDatacenterId(null);
        $configuration->setPrimaryAuthMode('roomSurname');
        $configuration->setDefaultDevice('generic');
        $configuration->setAvailableDevices(['android', 'ios', 'generic']);
        $configuration->setSsids([]);
        $configuration->setUpgradeEnabled(false);
        $configuration->setUpgradeUrl(null);
    }

    private function serializeConfiguration(HotelConfiguration|HotelConfigurationPreview|null $configuration): ?array
    {
        if ($configuration === null) {
            return null;
        }

        return [
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
            'createdAt' => $configuration->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $configuration->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
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
}
