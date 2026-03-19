<?php

namespace App\Entity;

use App\Repository\HotelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HotelRepository::class)]
#[ORM\Table(name: 'hotels')]
class Hotel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128, unique: true)]
    private string $externalHotelId;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'hotel', targetEntity: HotelConfiguration::class, cascade: ['persist', 'remove'])]
    private ?HotelConfiguration $configuration = null;

    public function __construct(string $externalHotelId, ?string $name = null)
    {
        $now = new \DateTimeImmutable('now');

        $this->externalHotelId = $externalHotelId;
        $this->name = $name;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalHotelId(): string
    {
        return $this->externalHotelId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getConfiguration(): ?HotelConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(HotelConfiguration $configuration): void
    {
        $this->configuration = $configuration;

        if ($configuration->getHotel() !== $this) {
            $configuration->setHotel($this);
        }

        $this->touch();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
