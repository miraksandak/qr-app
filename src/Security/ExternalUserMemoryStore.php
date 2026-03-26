<?php

namespace App\Security;

final class ExternalUserMemoryStore
{
    /**
     * @var array<string, ExternalUser>
     */
    private array $users = [];

    public function rememberUser(ExternalUser $user): void
    {
        $this->users[$user->getUserIdentifier()] = $user;
    }

    public function getUser(string $identifier): ?ExternalUser
    {
        return $this->users[$identifier] ?? null;
    }
}
