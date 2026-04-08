<?php

namespace App\Service;

final class HotelConfigurationPreview
{
    public function __construct(
        private ?string $supportText,
        private ?string $footerText,
        private ?string $logoUrl,
        private ?string $logoImageUuid,
        private ?string $portalUrl,
        private ?string $proxyApiBaseUrl,
        private ?string $datacenterId,
        private ?string $pmsProvider,
        private array $pmsCredentialFields,
        private string $primaryAuthMode,
        private string $defaultDevice,
        private array $availableDevices,
        private array $ssids,
        private bool $upgradeEnabled,
        private ?string $upgradeUrl
    ) {
    }

    public function getId(): ?int
    {
        return null;
    }

    public function getSupportText(): ?string
    {
        return $this->supportText;
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function getLogoImageUuid(): ?string
    {
        return $this->logoImageUuid;
    }

    public function getPortalUrl(): ?string
    {
        return $this->portalUrl;
    }

    public function getProxyApiBaseUrl(): ?string
    {
        return $this->proxyApiBaseUrl;
    }

    public function getDatacenterId(): ?string
    {
        return $this->datacenterId;
    }

    public function getPmsProvider(): ?string
    {
        return $this->pmsProvider;
    }

    public function getPmsCredentialFields(): array
    {
        return $this->pmsCredentialFields;
    }

    public function getPrimaryAuthMode(): string
    {
        return $this->primaryAuthMode;
    }

    public function getDefaultDevice(): string
    {
        return $this->defaultDevice;
    }

    public function getAvailableDevices(): array
    {
        return $this->availableDevices;
    }

    public function getSsids(): array
    {
        return $this->ssids;
    }

    public function isUpgradeEnabled(): bool
    {
        return $this->upgradeEnabled;
    }

    public function getUpgradeUrl(): ?string
    {
        return $this->upgradeUrl;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return null;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return null;
    }
}
