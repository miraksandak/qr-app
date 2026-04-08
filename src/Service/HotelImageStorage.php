<?php

namespace App\Service;

use App\Entity\Hotel;
use App\Entity\ImageAsset;
use App\Repository\HotelRepository;
use App\Repository\ImageAssetRepository;
use App\Repository\ManualRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HotelImageStorage
{
    private const ALLOWED_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImageAssetRepository $imageAssetRepository,
        private HotelRepository $hotelRepository,
        private ManualRecordRepository $manualRecordRepository,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.cache_dir%/hotel-image-cache')]
        private string $cacheDirectory
    ) {
    }

    public function storeUploadedLogo(Hotel $hotel, UploadedFile $uploadedFile): ImageAsset
    {
        $mimeType = $uploadedFile->getMimeType();
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Logo file must be PNG, JPG, GIF, or WEBP.');
        }

        $contents = file_get_contents($uploadedFile->getPathname());
        if (!is_string($contents) || $contents === '') {
            throw new \InvalidArgumentException('Uploaded logo file is empty.');
        }

        $asset = new ImageAsset($this->generateUuid(), $hotel, $mimeType, $contents);
        $this->entityManager->persist($asset);

        return $asset;
    }

    public function resolveLogoUrl(?string $imageUuid, ?string $fallbackUrl): ?string
    {
        if (is_string($imageUuid) && $imageUuid !== '') {
            return $this->urlGenerator->generate('app_image_asset_show', ['uuid' => $imageUuid]);
        }

        $normalizedFallback = is_string($fallbackUrl) ? trim($fallbackUrl) : '';

        return $normalizedFallback !== '' ? $normalizedFallback : null;
    }

    /**
     * @return array{data: string, mimeType: string}|null
     */
    public function load(string $uuid): ?array
    {
        $paths = $this->getCachePaths($uuid);
        if (is_file($paths['image']) && is_file($paths['mime'])) {
            $data = file_get_contents($paths['image']);
            $mimeType = file_get_contents($paths['mime']);
            if (is_string($data) && $data !== '' && is_string($mimeType) && $mimeType !== '') {
                return [
                    'data' => $data,
                    'mimeType' => trim($mimeType),
                ];
            }
        }

        $asset = $this->imageAssetRepository->findOneByUuid($uuid);
        if ($asset === null) {
            return null;
        }

        $payload = [
            'data' => $asset->getData(),
            'mimeType' => $asset->getMimeType(),
        ];

        $this->writeCache($uuid, $payload['data'], $payload['mimeType']);

        return $payload;
    }

    public function cleanupUnusedHotelImages(Hotel $hotel): void
    {
        $referencedUuids = $this->findReferencedImageUuids();

        foreach ($this->imageAssetRepository->findByHotel($hotel) as $asset) {
            if (in_array($asset->getUuid(), $referencedUuids, true)) {
                continue;
            }

            $this->deleteCache($asset->getUuid());
            $this->entityManager->remove($asset);
        }
    }

    /**
     * @return list<string>
     */
    public function findReferencedImageUuids(): array
    {
        $referencedUuids = [];

        foreach ($this->hotelRepository->findAll() as $hotel) {
            $configuration = $hotel->getConfiguration();
            if ($configuration === null) {
                continue;
            }

            $uuid = $configuration->getLogoImageUuid();
            if (is_string($uuid) && $uuid !== '') {
                $referencedUuids[] = $uuid;
            }
        }

        foreach ($this->manualRecordRepository->findActiveRecords(new \DateTimeImmutable('now')) as $record) {
            try {
                $payload = json_decode($record->getPayloadJson(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($payload)) {
                continue;
            }

            $uuid = $payload['hotel']['logoImageUuid'] ?? null;
            if (is_string($uuid) && trim($uuid) !== '') {
                $referencedUuids[] = trim($uuid);
            }
        }

        return array_values(array_unique($referencedUuids));
    }

    private function writeCache(string $uuid, string $data, string $mimeType): void
    {
        $paths = $this->getCachePaths($uuid);
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0775, true);
        }

        file_put_contents($paths['image'], $data);
        file_put_contents($paths['mime'], $mimeType);
    }

    private function deleteCache(string $uuid): void
    {
        $paths = $this->getCachePaths($uuid);
        @unlink($paths['image']);
        @unlink($paths['mime']);
    }

    /**
     * @return array{image: string, mime: string}
     */
    private function getCachePaths(string $uuid): array
    {
        $safeUuid = preg_replace('/[^a-zA-Z0-9-]/', '', $uuid) ?: $uuid;

        return [
            'image' => rtrim($this->cacheDirectory, '/') . '/' . $safeUuid . '.image',
            'mime' => rtrim($this->cacheDirectory, '/') . '/' . $safeUuid . '.mimeType',
        ];
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
