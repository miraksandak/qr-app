<?php

namespace App\Connector;

final class ExternalAuthorizationContext
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private array $roles = [],
        private bool $accessAllHotels = false
    ) {
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $role): string => is_string($role) ? trim($role) : '', $this->roles),
            static fn (string $role): bool => $role !== ''
        )));
    }

    public function canAccessAllHotels(): bool
    {
        return $this->accessAllHotels;
    }
}
