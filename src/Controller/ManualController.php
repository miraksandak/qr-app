<?php

namespace App\Controller;

use App\Connector\ExternalHotelAccess;
use App\Entity\ManualRecord;
use App\Repository\HotelRepository;
use App\Repository\ManualRecordRepository;
use App\Security\ExternalUser;
use App\Service\HotelConfigurationManager;
use App\Service\ManualPayloadViewBuilder;
use App\Service\ManualUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class ManualController extends AbstractController
{
    private const ID_LENGTH = 5;
    private const ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const DEFAULT_TEMPLATE = [
        'hotel' => [
            'name' => 'Hotel Guest',
            'supportText' => null,
            'logoUrl' => null,
            'footerText' => null,
            'portalUrl' => null,
            'ssids' => [],
            'upgrade' => [
                'enabled' => false,
                'url' => null,
            ],
        ],
        'device' => [
            'default' => 'generic',
            'available' => ['android', 'ios', 'generic'],
        ],
        'options' => [
            'mode' => 'roomSurname',
            'roomSurname' => ['room' => '606', 'surname' => 'Doe'],
            'accessCode' => ['code' => 'ABCD-1234'],
            'freeAccess' => [
                'enabled' => false,
            ],
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualUrlGenerator $manualUrlGenerator,
        #[Autowire('%env(int:DEFAULT_TTL_DAYS)%')]
        private int $defaultTtlDays
    ) {
    }

    #[Route('/api/manual', name: 'api_manual_create', methods: ['POST'])]
    #[Route('/app/api/manual', name: 'app_api_manual_create', methods: ['POST'])]
    public function createManual(
        Request $request,
        ManualRecordRepository $repository,
        HotelConfigurationManager $hotelConfigurationManager,
        HotelRepository $hotelRepository
    ): JsonResponse {
        $user = $this->getAuthenticatedUser();
        if (!$user->canManageAccess()) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
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

        $hotelExternalId = $this->extractHotelExternalId($payload);
        if ($hotelExternalId === null) {
            return new JsonResponse(['error' => 'hotelExternalId is required'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $this->sanitizePayload($payload);

        try {
            $validUntil = $this->resolveValidUntil($payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $accessibleHotel = $this->findAccessibleHotel($hotelExternalId, $hotelRepository);
        if ($accessibleHotel === null) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $hotel = $hotelConfigurationManager->ensureHotelWithConfiguration($accessibleHotel);
        $configuration = $hotel->getConfiguration();
        if ($configuration === null) {
            return new JsonResponse(['error' => 'Hotel configuration is missing'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $authPayload = $hotelConfigurationManager->buildManualAuthPayload(
                $configuration,
                is_array($payload['options'] ?? null) ? $payload['options'] : []
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if (isset($authPayload['accessCode']) && !$hotelConfigurationManager->isAccessCodeAllowed($configuration)) {
            return new JsonResponse([
                'error' => 'Access code cannot be created until the hotel configuration is complete.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $mergedPayload = $this->deepMerge(
            self::DEFAULT_TEMPLATE,
            $hotelConfigurationManager->buildManualHotelPayload($hotel)
        );
        $mergedPayload = $this->deepMerge($mergedPayload, $payload);
        $mergedPayload['options'] = $this->deepMerge(
            is_array($mergedPayload['options'] ?? null) ? $mergedPayload['options'] : [],
            $authPayload
        );
        $mergedPayload = $this->appendAuthTargetUrls($mergedPayload);

        try {
            $payloadJson = json_encode($mergedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Unable to encode payload'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $id = $this->generateId($repository);

        $record = new ManualRecord(
            $id,
            $payloadJson,
            $validUntil,
            new \DateTimeImmutable('now')
        );

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        $response = [
            'id' => $id,
            'viewerUrl' => $this->manualUrlGenerator->buildViewerUrl($id),
            'jsonUrl' => $this->manualUrlGenerator->buildJsonUrl($id),
            'printUrl' => $this->manualUrlGenerator->buildPrintUrl($id),
            'upgradeUrl' => $this->manualUrlGenerator->buildUpgradeUrl($id),
            'validUntil' => $validUntil->format(\DateTimeInterface::ATOM),
            'hotelExternalId' => $hotelExternalId,
        ];

        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    #[Route('/json/{id}', name: 'manual_json', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'], priority: 10)]
    public function manualJson(
        string $id,
        ManualRecordRepository $repository,
        ManualPayloadViewBuilder $manualPayloadViewBuilder
    ): JsonResponse
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $payload = json_decode($record->getPayloadJson(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Stored payload is invalid'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Stored payload is invalid'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($manualPayloadViewBuilder->build($payload));
    }

    #[Route('/upgrade/{id}', name: 'manual_upgrade', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'], priority: 10)]
    public function upgrade(string $id, ManualRecordRepository $repository): Response
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->render('manual/upgrade.html.twig', [
            'id' => strtoupper($id),
            'page' => 'upgrade',
            'baseViewerUrl' => $this->manualUrlGenerator->getViewerBaseUrl(),
            'baseUpgradeUrl' => $this->manualUrlGenerator->getUpgradeBaseUrl(),
        ]);
    }

    #[Route('/print/{id}', name: 'manual_print', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'], priority: 10)]
    public function print(string $id, ManualRecordRepository $repository): Response
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->render('manual/viewer.html.twig', [
            'id' => strtoupper($id),
            'page' => 'print',
            'baseViewerUrl' => $this->manualUrlGenerator->getViewerBaseUrl(),
            'baseUpgradeUrl' => $this->manualUrlGenerator->getUpgradeBaseUrl(),
        ]);
    }

    #[Route('/{id}', name: 'manual_viewer', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'], priority: -100)]
    public function viewer(string $id, ManualRecordRepository $repository): Response
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->render('manual/viewer.html.twig', [
            'id' => strtoupper($id),
            'page' => 'viewer',
            'baseViewerUrl' => $this->manualUrlGenerator->getViewerBaseUrl(),
            'baseUpgradeUrl' => $this->manualUrlGenerator->getUpgradeBaseUrl(),
        ]);
    }

    private function resolveValidUntil(array $payload): \DateTimeImmutable
    {
        $raw = $payload['validUntil'] ?? $payload['valid_until'] ?? null;
        if ($raw === null) {
            return new \DateTimeImmutable('+' . $this->defaultTtlDays . ' days');
        }

        if (!is_string($raw) || trim($raw) === '') {
            throw new \InvalidArgumentException('validUntil must be an ISO date string');
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('validUntil must be an ISO date string');
        }
    }

    private function generateId(ManualRecordRepository $repository): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->randomId();
            if ($repository->find($candidate) === null) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique ID');
    }

    private function randomId(): string
    {
        $chars = self::ID_CHARS;
        $max = strlen($chars) - 1;
        $id = '';

        for ($i = 0; $i < self::ID_LENGTH; $i++) {
            $id .= $chars[random_int(0, $max)];
        }

        return $id;
    }

    private function findActiveRecord(string $id, ManualRecordRepository $repository): ?ManualRecord
    {
        $id = strtoupper($id);
        if (!$this->isValidId($id)) {
            return null;
        }

        $record = $repository->find($id);
        if ($record === null) {
            return null;
        }

        $now = new \DateTimeImmutable('now');
        if ($record->getValidUntil() <= $now) {
            return null;
        }

        return $record;
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{' . self::ID_LENGTH . '}$/', $id);
    }

    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && array_key_exists($key, $base) && is_array($base[$key])) {
                if ($this->isList($value) || $this->isList($base[$key])) {
                    $base[$key] = $value;
                } else {
                    $base[$key] = $this->deepMerge($base[$key], $value);
                }
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function sanitizePayload(array $payload): array
    {
        unset($payload['steps'], $payload['hotelExternalId'], $payload['manual']);

        if (isset($payload['hotel']) && is_array($payload['hotel'])) {
            unset($payload['hotel']['externalHotelId']);
        }

        if (isset($payload['options']) && is_array($payload['options'])) {
            unset($payload['options']['fields'], $payload['options']['variants']);
        }

        if (isset($payload['upgrade']) && is_array($payload['upgrade'])) {
            unset($payload['upgrade']['title'], $payload['upgrade']['body']);
        }

        if (isset($payload['hotel']['upgrade']) && is_array($payload['hotel']['upgrade'])) {
            unset($payload['hotel']['upgrade']['title'], $payload['hotel']['upgrade']['body']);
        }

        return $payload;
    }

    private function extractHotelExternalId(array $payload): ?string
    {
        $hotelExternalId = $payload['hotelExternalId'] ?? $payload['hotel']['externalHotelId'] ?? null;
        if (!is_string($hotelExternalId) || trim($hotelExternalId) === '') {
            return null;
        }

        return trim($hotelExternalId);
    }

    private function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $item) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    private function getAuthenticatedUser(): ExternalUser
    {
        $user = $this->getUser();
        if (!$user instanceof ExternalUser) {
            throw $this->createAccessDeniedException('External user is required.');
        }

        return $user;
    }

    private function findAccessibleHotel(string $externalHotelId, HotelRepository $hotelRepository): ?ExternalHotelAccess
    {
        $user = $this->getAuthenticatedUser();
        $hotel = $user->findAccessibleHotel($externalHotelId);
        if ($hotel !== null) {
            return $hotel;
        }

        if ($user->canAccessAllHotels()) {
            $storedHotel = $hotelRepository->findOneByExternalHotelId($externalHotelId);
            if ($storedHotel !== null) {
                return new ExternalHotelAccess($storedHotel->getExternalHotelId(), $storedHotel->getName());
            }
        }

        return null;
    }

    private function appendAuthTargetUrls(array $payload): array
    {
        $portalUrl = trim((string) ($payload['hotel']['portalUrl'] ?? ''));
        if ($portalUrl === '') {
            return $payload;
        }

        $portalBase = rtrim($portalUrl, '/');
        $code = trim((string) ($payload['options']['accessCode']['code'] ?? ''));
        if ($code !== '') {
            $url = $portalBase . '/access-code?code=' . rawurlencode($code);
            $payload['options']['accessCode']['url'] = $url;
            $payload['options']['ac']['url'] = $url;
        }

        $pmsValues = is_array($payload['options']['pms'] ?? null) ? $payload['options']['pms'] : [];
        if ($pmsValues !== []) {
            $query = [];
            foreach ($pmsValues as $key => $value) {
                if (!is_string($key) || in_array($key, ['provider', 'fields', 'url'], true)) {
                    continue;
                }

                $normalizedValue = trim((string) $value);
                if ($normalizedValue === '') {
                    continue;
                }

                $query[$key] = $normalizedValue;
            }

            if (isset($query['roomNumber']) && !isset($query['room'])) {
                $query['room'] = $query['roomNumber'];
            }

            if ($query !== []) {
                $url = $portalBase . '/room-login?' . http_build_query($query);
                $payload['options']['pms']['url'] = $url;
                $payload['options']['roomSurname']['url'] = $url;
            }
        }

        return $payload;
    }
}
