<?php

namespace App\Entity;

use App\Repository\HotelConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HotelConfigurationRepository::class)]
#[ORM\Table(name: 'hotel_configurations')]
class HotelConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'configuration', targetEntity: Hotel::class)]
    #[ORM\JoinColumn(name: 'hotel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    private Hotel $hotel;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $supportText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $footerText = null;

    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $portalUrl = null;

    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $proxyApiBaseUrl = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $datacenterId = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $primaryAuthMode = 'roomSurname';

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $defaultDevice = 'generic';

    #[ORM\Column(type: Types::JSON)]
    private array $availableDevices = ['android', 'ios', 'generic'];

    #[ORM\Column(type: Types::JSON)]
    private array $ssids = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $upgradeEnabled = false;

    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $upgradeUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Hotel $hotel)
    {
        $now = new \DateTimeImmutable('now');

        $this->hotel = $hotel;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHotel(): Hotel
    {
        return $this->hotel;
    }

    public function setHotel(Hotel $hotel): void
    {
        $this->hotel = $hotel;

        if ($hotel->getConfiguration() !== $this) {
            $hotel->setConfiguration($this);
        }

        $this->touch();
    }

    public function getSupportText(): ?string
    {
        return $this->supportText;
    }

    public function setSupportText(?string $supportText): void
    {
        $this->supportText = $supportText;
        $this->touch();
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): void
    {
        $this->footerText = $footerText;
        $this->touch();
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): void
    {
        $this->logoUrl = $logoUrl;
        $this->touch();
    }

    public function getPortalUrl(): ?string
    {
        return $this->portalUrl;
    }

    public function setPortalUrl(?string $portalUrl): void
    {
        $this->portalUrl = $portalUrl;
        $this->touch();
    }

    public function getProxyApiBaseUrl(): ?string
    {
        return $this->proxyApiBaseUrl;
    }

    public function setProxyApiBaseUrl(?string $proxyApiBaseUrl): void
    {
        $this->proxyApiBaseUrl = $proxyApiBaseUrl;
        $this->touch();
    }

    public function getDatacenterId(): ?string
    {
        return $this->datacenterId;
    }

    public function setDatacenterId(?string $datacenterId): void
    {
        $this->datacenterId = $datacenterId;
        $this->touch();
    }

    public function getPrimaryAuthMode(): string
    {
        return $this->primaryAuthMode;
    }

    public function setPrimaryAuthMode(string $primaryAuthMode): void
    {
        $this->primaryAuthMode = $primaryAuthMode;
        $this->touch();
    }

    public function getDefaultDevice(): string
    {
        return $this->defaultDevice;
    }

    public function setDefaultDevice(string $defaultDevice): void
    {
        $this->defaultDevice = $defaultDevice;
        $this->touch();
    }

    public function getAvailableDevices(): array
    {
        return $this->availableDevices;
    }

    public function setAvailableDevices(array $availableDevices): void
    {
        $this->availableDevices = array_values($availableDevices);
        $this->touch();
    }

    public function getSsids(): array
    {
        return $this->ssids;
    }

    public function setSsids(array $ssids): void
    {
        $this->ssids = array_values($ssids);
        $this->touch();
    }

    public function isUpgradeEnabled(): bool
    {
        return $this->upgradeEnabled;
    }

    public function setUpgradeEnabled(bool $upgradeEnabled): void
    {
        $this->upgradeEnabled = $upgradeEnabled;
        $this->touch();
    }

    public function getUpgradeUrl(): ?string
    {
        return $this->upgradeUrl;
    }

    public function setUpgradeUrl(?string $upgradeUrl): void
    {
        $this->upgradeUrl = $upgradeUrl;
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

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
        $this->hotel->touch();
    }
}
