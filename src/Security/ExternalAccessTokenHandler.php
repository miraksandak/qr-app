<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ExternalAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ExternalUserProvider $userProvider
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        try {
            $user = $this->userProvider->loadUserByAccessToken($accessToken);
        } catch (AuthenticationException $exception) {
            throw $exception;
        }

        return new UserBadge($user->getUserIdentifier());
    }
}
