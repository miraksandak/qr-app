<?php

namespace App\Controller;

use App\Entity\ManualRecord;
use App\Repository\ManualRecordRepository;
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
            'ssid' => 'Hotel-Guest',
            'supportText' => 'Need help? Contact reception.',
            'logoUrl' => '/media/mik-logo.png',
        ],
        'device' => [
            'default' => 'generic',
            'available' => ['android', 'ios', 'generic'],
        ],
        'steps' => [
            'android' => [
                'Open Wi-Fi settings on your phone.',
                'Select the hotel network.',
                'Enter the login details and tap Connect.',
            ],
            'ios' => [
                'Open Settings and tap Wi-Fi.',
                'Choose the hotel network.',
                'Fill in the login details and tap Join.',
            ],
            'generic' => [
                'Open Wi-Fi settings on your device.',
                'Select the hotel network.',
                'Provide the login details and connect.',
            ],
        ],
        'options' => [
            'roomSurname' => ['room' => '606', 'surname' => 'Doe'],
            'accessCode' => ['code' => 'ABCD-1234'],
            'freeAccess' => [],
        ],
        'upgrade' => [
            'enabled' => true,
            'title' => 'Improve connection',
            'body' => 'If connected but experiencing issues, open this page.',
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(BASE_VIEWER_URL)%')]
        private string $baseViewerUrl,
        #[Autowire('%env(BASE_UPGRADE_URL)%')]
        private string $baseUpgradeUrl,
        #[Autowire('%env(int:DEFAULT_TTL_DAYS)%')]
        private int $defaultTtlDays
    ) {
    }

    #[Route('/api/manual', name: 'api_manual_create', methods: ['POST'])]
    public function createManual(Request $request, ManualRecordRepository $repository): JsonResponse
    {
        $apiKey = (string) $request->headers->get('X-API-Key');
        if ($apiKey !== $this->apiKey) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
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
            $validUntil = $this->resolveValidUntil($payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $mergedPayload = $this->deepMerge(self::DEFAULT_TEMPLATE, $payload);

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

        $viewerBase = $this->normalizeBaseUrl($this->baseViewerUrl);
        $response = [
            'id' => $id,
            'viewerUrl' => $viewerBase . '/' . $id,
            'jsonUrl' => $viewerBase . '/json/' . $id,
            'validUntil' => $validUntil->format(\DateTimeInterface::ATOM),
        ];

        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    #[Route('/json/{id}', name: 'manual_json', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'])]
    public function manualJson(string $id, ManualRecordRepository $repository): JsonResponse
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($record->getPayloadJson(), Response::HTTP_OK, [], true);
    }

    #[Route('/upgrade/{id}', name: 'manual_upgrade', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'])]
    public function upgrade(string $id, ManualRecordRepository $repository): Response
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->render('manual/upgrade.html.twig', [
            'id' => strtoupper($id),
            'page' => 'upgrade',
            'baseViewerUrl' => $this->normalizeBaseUrl($this->baseViewerUrl),
            'baseUpgradeUrl' => $this->normalizeBaseUrl($this->baseUpgradeUrl),
        ]);
    }

    #[Route('/{id}', name: 'manual_viewer', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9]{5}'])]
    public function viewer(string $id, ManualRecordRepository $repository): Response
    {
        $record = $this->findActiveRecord($id, $repository);
        if ($record === null) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->render('manual/viewer.html.twig', [
            'id' => strtoupper($id),
            'page' => 'viewer',
            'baseViewerUrl' => $this->normalizeBaseUrl($this->baseViewerUrl),
            'baseUpgradeUrl' => $this->normalizeBaseUrl($this->baseUpgradeUrl),
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

    private function normalizeBaseUrl(string $url): string
    {
        return rtrim($url, '/');
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
}
