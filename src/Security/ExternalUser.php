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
        private \DateTimeImmutable $syncedAt
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
    public function withRefreshedContext(ExternalOauthToken $oauthToken, array $accessibleHotels, \DateTimeImmutable $syncedAt): self
    {
        return new self(
            $this->userIdentifier,
            $this->displayName,
            $oauthToken,
            $accessibleHotels,
            $syncedAt
        );
    }

    public function getRoles(): array
    {
        return ['ROLE_EXTERNAL_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
