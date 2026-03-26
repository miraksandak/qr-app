<?php

namespace App\Security;

use App\Connector\ExternalAuthenticationException;
use App\Connector\ExternalConnectorClientInterface;
use App\Connector\ExternalConnectorException;
use App\Connector\ExternalOauthToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class ExternalUserProvider implements UserProviderInterface
{
    public function __construct(
        private ExternalConnectorClientInterface $connectorClient,
        private ExternalUserMemoryStore $userMemoryStore,
        #[Autowire('%env(int:MIKENOPA_USER_REFRESH_INTERVAL)%')]
        private int $refreshIntervalSeconds,
        #[Autowire('%env(int:MIKENOPA_TOKEN_REFRESH_SKEW)%')]
        private int $refreshSkewSeconds
    ) {
    }

    public function loadUserFromCredentials(string $username, string $password): ExternalUser
    {
        $identifier = trim($username);
        if ($identifier === '') {
            throw new CustomUserMessageAuthenticationException('Username is required.');
        }

        if ($password === '') {
            throw new CustomUserMessageAuthenticationException('Password is required.');
        }

        try {
            $oauthToken = $this->connectorClient->exchangePasswordForToken($identifier, $password);
            $hotels = $this->connectorClient->fetchAccessibleHotels($oauthToken->getAccessToken());
        } catch (ExternalAuthenticationException $exception) {
            throw new CustomUserMessageAuthenticationException('Invalid username or password.', [], 0, $exception);
        } catch (ExternalConnectorException $exception) {
            throw new AuthenticationServiceException($exception->getMessage(), 0, $exception);
        }

        return $this->rememberUser(new ExternalUser(
            $identifier,
            $identifier,
            $oauthToken,
            $hotels,
            new \DateTimeImmutable('now')
        ));
    }

    public function loadUserByAccessToken(string $accessToken): ExternalUser
    {
        try {
            $hotels = $this->connectorClient->fetchAccessibleHotels($accessToken);
        } catch (ExternalAuthenticationException $exception) {
            throw new BadCredentialsException('The access token is invalid or expired.', 0, $exception);
        } catch (ExternalConnectorException $exception) {
            throw new AuthenticationServiceException($exception->getMessage(), 0, $exception);
        }

        return $this->rememberUser(new ExternalUser(
            'token:' . hash('sha256', $accessToken),
            'API token',
            new ExternalOauthToken($accessToken),
            $hotels,
            new \DateTimeImmutable('now')
        ));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ExternalUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $now = new \DateTimeImmutable('now');
        $secondsSinceSync = $now->getTimestamp() - $user->getSyncedAt()->getTimestamp();
        if ($secondsSinceSync < $this->refreshIntervalSeconds) {
            return $this->rememberUser($user);
        }

        $oauthToken = $user->getOauthToken();

        try {
            if ($oauthToken->needsRefresh($now, $this->refreshSkewSeconds)) {
                $refreshToken = $oauthToken->getRefreshToken();
                if ($refreshToken === null) {
                    throw new UserNotFoundException('The external session has expired.');
                }

                $oauthToken = $this->connectorClient->refreshAccessToken($refreshToken);
            } elseif ($oauthToken->isExpired($now, $this->refreshSkewSeconds)) {
                throw new UserNotFoundException('The external session has expired.');
            }

            $hotels = $this->connectorClient->fetchAccessibleHotels($oauthToken->getAccessToken());
        } catch (ExternalAuthenticationException $exception) {
            $userNotFound = new UserNotFoundException('The external session is no longer valid.');
            $userNotFound->setUserIdentifier($user->getUserIdentifier());

            throw $userNotFound;
        } catch (ExternalConnectorException $exception) {
            return $this->rememberUser($user);
        }

        return $this->rememberUser($user->withRefreshedContext($oauthToken, $hotels, $now));
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, ExternalUser::class, true);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userMemoryStore->getUser($identifier);
        if ($user !== null) {
            return $user;
        }

        $exception = new UserNotFoundException(sprintf('User "%s" was not preloaded in the current request.', $identifier));
        $exception->setUserIdentifier($identifier);

        throw $exception;
    }

    private function rememberUser(ExternalUser $user): ExternalUser
    {
        $this->userMemoryStore->rememberUser($user);

        return $user;
    }
}
