<?php

namespace App\Connector;

final class ExternalOauthToken
{
    public function __construct(
        private string $accessToken,
        private ?string $refreshToken = null,
        private ?\DateTimeImmutable $expiresAt = null,
        private array $scopes = []
    ) {
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function isExpired(\DateTimeImmutable $now, int $skewSeconds = 0): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= $now->modify(sprintf('+%d seconds', max(0, $skewSeconds)));
    }

    public function needsRefresh(\DateTimeImmutable $now, int $skewSeconds = 60): bool
    {
        return $this->refreshToken !== null && $this->isExpired($now, $skewSeconds);
    }
}
