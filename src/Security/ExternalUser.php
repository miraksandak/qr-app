<?php

namespace App\Security;

use App\Connector\ExternalHotelAccess;
use App\Connector\ExternalOauthToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class ExternalUser implements UserInterface
{
    /**
     * @param list<ExternalHotelAccess> $accessibleHotels
     */
    public function __construct(
        private string $userIdentifier,
        private string $displayName,
        private ExternalOauthToken $oauthToken,
        private array $accessibleHotels,
        private \DateTimeImmutable $syncedAt,
        private array $roles = ['ROLE_EXTERNAL_USER'],
        private bool $accessAllHotels = false,
        private bool $internalApiUser = false
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getOauthToken(): ExternalOauthToken
    {
        return $this->oauthToken;
    }

    /**
     * @return list<ExternalHotelAccess>
     */
    public function getAccessibleHotels(): array
    {
        return $this->accessibleHotels;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function hasAccessToHotel(string $externalHotelId): bool
    {
        if ($this->accessAllHotels) {
            return true;
        }

        return $this->findAccessibleHotel($externalHotelId) !== null;
    }

    public function findAccessibleHotel(string $externalHotelId): ?ExternalHotelAccess
    {
        foreach ($this->accessibleHotels as $hotel) {
            if ($hotel->getExternalHotelId() === $externalHotelId) {
                return $hotel;
            }
        }

        return null;
    }

    /**
     * @param list<ExternalHotelAccess> $accessibleHotels
     */
    public function withRefreshedContext(
        ExternalOauthToken $oauthToken,
        array $accessibleHotels,
        \DateTimeImmutable $syncedAt,
        ?array $roles = null,
        ?bool $accessAllHotels = null
    ): self
    {
        return new self(
            $this->userIdentifier,
            $this->displayName,
            $oauthToken,
            $accessibleHotels,
            $syncedAt,
            $roles ?? $this->roles,
            $accessAllHotels ?? $this->accessAllHotels,
            $this->internalApiUser
        );
    }

    public function getRoles(): array
    {
        return array_values(array_unique(array_merge(['ROLE_EXTERNAL_USER'], $this->roles)));
    }

    public function canManageAccess(): bool
    {
        return in_array('ROLE_ACCESS_MANAGER', $this->getRoles(), true);
    }

    public function canManageHotelSettings(): bool
    {
        return in_array('ROLE_HOTEL_SETTINGS_MANAGER', $this->getRoles(), true);
    }

    public function canAccessAllHotels(): bool
    {
        return $this->accessAllHotels;
    }

    public function isInternalApiUser(): bool
    {
        return $this->internalApiUser;
    }

    public function eraseCredentials(): void
    {
    }
}
