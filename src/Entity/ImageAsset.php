<?php

namespace App\Entity;

use App\Repository\ImageAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImageAssetRepository::class)]
#[ORM\Table(name: 'image_assets')]
class ImageAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Hotel::class)]
    #[ORM\JoinColumn(name: 'hotel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Hotel $hotel;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $mimeType;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $data;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $uuid, Hotel $hotel, string $mimeType, string $data)
    {
        $this->uuid = $uuid;
        $this->hotel = $hotel;
        $this->mimeType = $mimeType;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getHotel(): Hotel
    {
        return $this->hotel;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getData(): string
    {
        if (is_resource($this->data)) {
            rewind($this->data);
            $contents = stream_get_contents($this->data);

            return is_string($contents) ? $contents : '';
        }

        return (string) $this->data;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
