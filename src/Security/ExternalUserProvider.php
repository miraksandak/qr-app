<?php

namespace App\Security;

use App\Connector\ExternalAuthorizationContext;
use App\Connector\ExternalAuthenticationException;
use App\Connector\ExternalConnectorClientInterface;
use App\Connector\ExternalConnectorException;
use App\Connector\ExternalHotelAccess;
use App\Connector\ExternalOauthToken;
use App\Repository\HotelRepository;
use Psr\Log\LoggerInterface;
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
    private const UNRESOLVED_AUTHORIZATION_REFRESH_SECONDS = 30;
    private const SUSPICIOUS_TRUNCATED_HOTELS_COUNT = 50;

    private const ROLE_KEYS = [
        'role',
        'roles',
        'userrole',
        'userroles',
        'approle',
        'approles',
        'permission',
        'permissions',
        'authority',
        'authorities',
    ];

    private const ROLE_VALUE_KEYS = [
        'role',
        'roles',
        'permission',
        'permissions',
        'name',
        'names',
        'code',
        'codes',
        'key',
        'keys',
        'value',
        'values',
        'slug',
        'identifier',
    ];

    private const EXPLICIT_PERMISSION_KEYS = [
        'canmanageaccess' => 'canManageAccess',
        'canmanagehotelsettings' => 'canManageHotelSettings',
    ];

    private const GLOBAL_MANAGEMENT_ROLES = [
        'ROLE_MK_SUPPORT',
        'ROLE_MK_SUPPORT_MANAGER',
        'ROLE_TECHNICIAN',
        'ROLE_ADMIN',
        'ROLE_SUPERADMIN',
    ];

    private const ACCESS_MANAGER_ROLES = [
        'ROLE_MK_SUPPORT',
        'ROLE_MK_SUPPORT_MANAGER',
        'ROLE_TECHNICIAN_LIMITED',
        'ROLE_TECHNICIAN',
        'ROLE_ADMIN',
        'ROLE_SUPERADMIN',
        'ROLE_HOTEL_FRONT_DESK_LIMITED',
        'ROLE_HOTEL_IT_STAFF',
        'ROLE_HOTEL_FRONT_DESK',
        'ROLE_HOTEL_ACCESS_CODE_MULTICODE',
        'ROLE_HOTEL_ACCESS_CODE_PERSONAL',
        'ROLE_HOTEL_ACCESS_CODE_EVENT',
        'ROLE_MANUAL_OPERATIONS_REDUCED',
        'ROLE_MANUAL_OPERATIONS_FULL',
        'ROLE_ACCESS_CODES_EVENT',
        'ROLE_ACCESS_CODES_PERSONAL',
        'ROLE_ACCESS_CODES_PROMO',
        'ROLE_ACCESS_CODES_MULTI',
    ];

    private const HOTEL_SETTINGS_MANAGER_ROLES = [
        'ROLE_MK_SUPPORT',
        'ROLE_MK_SUPPORT_MANAGER',
        'ROLE_TECHNICIAN_LIMITED',
        'ROLE_TECHNICIAN',
        'ROLE_ADMIN',
        'ROLE_SUPERADMIN',
        'ROLE_HOTEL_IT_STAFF',
        'ROLE_MANUAL_OPERATIONS_FULL',
        'ROLE_MANAGE_EVERYTHING_WITHIN_HOTEL',
        'ROLE_ROLES_DELEGATE_HOTEL_ROLES',
        'ROLE_ROLES_DELEGATE_HOTEL_STAFF_ROLES',
    ];

    private const ACCESS_ALL_HOTELS_GRANTS = [
        'ROLE_MK_SUPPORT',
        'ROLE_MK_SUPPORT_MANAGER',
        'ROLE_TECHNICIAN',
        'ROLE_ADMIN',
        'ROLE_SUPERADMIN',
        'ROLE_GUESTACCESS',
        'ROLE_ACCESS_CODE_EVENT_LIBRARY',
        'ROLE_ACCESS_ALL_HOTELS',
    ];

    public function __construct(
        private ExternalConnectorClientInterface $connectorClient,
        private ExternalUserMemoryStore $userMemoryStore,
        private HotelRepository $hotelRepository,
        #[Autowire('%env(int:MIKENOPA_USER_REFRESH_INTERVAL)%')]
        private int $refreshIntervalSeconds,
        #[Autowire('%env(int:MIKENOPA_TOKEN_REFRESH_SKEW)%')]
        private int $refreshSkewSeconds,
        #[Autowire('%env(default::APP_INTERNAL_API_TOKEN)%')]
        private ?string $internalApiToken = null,
        private ?LoggerInterface $logger = null
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

        $connectorAuthorization = $this->fetchConnectorAuthorizationContext($oauthToken->getAccessToken(), $identifier);
        $authorization = $this->resolveAuthorization($hotels, $connectorAuthorization);
        $this->logUnresolvedAuthorization($identifier, $hotels, $authorization, $connectorAuthorization);

        return $this->rememberUser(new ExternalUser(
            $identifier,
            $identifier,
            $oauthToken,
            $hotels,
            new \DateTimeImmutable('now'),
            $authorization['roles'],
            $authorization['accessAllHotels']
        ));
    }

    public function loadUserByAccessToken(string $accessToken): ExternalUser
    {
        $internalToken = is_string($this->internalApiToken) ? trim($this->internalApiToken) : '';
        if ($internalToken !== '' && hash_equals($internalToken, $accessToken)) {
            return $this->rememberUser(new ExternalUser(
                'internal-api',
                'Internal API',
                new ExternalOauthToken($accessToken),
                $this->loadPersistedHotels(),
                new \DateTimeImmutable('now'),
                ['ROLE_INTERNAL_API', 'ROLE_ACCESS_MANAGER', 'ROLE_HOTEL_SETTINGS_MANAGER'],
                true,
                true
            ));
        }

        try {
            $hotels = $this->connectorClient->fetchAccessibleHotels($accessToken);
        } catch (ExternalAuthenticationException $exception) {
            throw new BadCredentialsException('The access token is invalid or expired.', 0, $exception);
        } catch (ExternalConnectorException $exception) {
            throw new AuthenticationServiceException($exception->getMessage(), 0, $exception);
        }

        $hashedTokenIdentifier = 'token:' . hash('sha256', $accessToken);
        $connectorAuthorization = $this->fetchConnectorAuthorizationContext($accessToken, $hashedTokenIdentifier);
        $authorization = $this->resolveAuthorization($hotels, $connectorAuthorization);
        $this->logUnresolvedAuthorization($hashedTokenIdentifier, $hotels, $authorization, $connectorAuthorization);

        return $this->rememberUser(new ExternalUser(
            $hashedTokenIdentifier,
            'API token',
            new ExternalOauthToken($accessToken),
            $hotels,
            new \DateTimeImmutable('now'),
            $authorization['roles'],
            $authorization['accessAllHotels']
        ));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ExternalUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $now = new \DateTimeImmutable('now');
        $secondsSinceSync = $now->getTimestamp() - $user->getSyncedAt()->getTimestamp();
        $shouldRefreshUnresolvedAuthorization = !$user->isInternalApiUser()
            && !$user->canManageAccess()
            && !$user->canManageHotelSettings()
            && !$user->canAccessAllHotels()
            && $secondsSinceSync >= min(self::UNRESOLVED_AUTHORIZATION_REFRESH_SECONDS, $this->refreshIntervalSeconds);
        $shouldRefreshPossiblyTruncatedHotels = !$user->isInternalApiUser()
            && $user->canAccessAllHotels()
            && count($user->getAccessibleHotels()) === self::SUSPICIOUS_TRUNCATED_HOTELS_COUNT;

        if ($secondsSinceSync < $this->refreshIntervalSeconds && !$shouldRefreshUnresolvedAuthorization && !$shouldRefreshPossiblyTruncatedHotels) {
            return $this->rememberUser($user);
        }

        $oauthToken = $user->getOauthToken();

        if ($user->isInternalApiUser()) {
            return $this->rememberUser($user->withRefreshedContext(
                $oauthToken,
                $this->loadPersistedHotels(),
                $now
            ));
        }

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

        $connectorAuthorization = $this->fetchConnectorAuthorizationContext($oauthToken->getAccessToken(), $user->getUserIdentifier());
        $authorization = $this->resolveAuthorization($hotels, $connectorAuthorization);
        $this->logUnresolvedAuthorization($user->getUserIdentifier(), $hotels, $authorization, $connectorAuthorization);

        return $this->rememberUser($user->withRefreshedContext(
            $oauthToken,
            $hotels,
            $now,
            $authorization['roles'],
            $authorization['accessAllHotels']
        ));
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

    /**
     * @param list<ExternalHotelAccess> $hotels
     *
     * @return array{roles: list<string>, accessAllHotels: bool}
     */
    private function resolveAuthorization(array $hotels, ?ExternalAuthorizationContext $connectorAuthorization = null): array
    {
        $explicitPermissions = [
            'access' => null,
            'hotelSettings' => null,
        ];
        $rolePermissions = [
            'access' => false,
            'hotelSettings' => false,
        ];
        $accessAllHotels = false;

        if ($connectorAuthorization !== null) {
            foreach ($connectorAuthorization->getRoles() as $role) {
                $normalizedRole = $this->normalizeRoleToken($role);
                if ($normalizedRole === '') {
                    continue;
                }

                if (in_array($normalizedRole, self::ACCESS_MANAGER_ROLES, true)) {
                    $rolePermissions['access'] = true;
                }

                if (in_array($normalizedRole, self::HOTEL_SETTINGS_MANAGER_ROLES, true)) {
                    $rolePermissions['hotelSettings'] = true;
                }

                if (in_array($normalizedRole, self::GLOBAL_MANAGEMENT_ROLES, true) || in_array($normalizedRole, self::ACCESS_ALL_HOTELS_GRANTS, true)) {
                    $accessAllHotels = true;
                }
            }

            if ($connectorAuthorization->canAccessAllHotels()) {
                $accessAllHotels = true;
            }
        }

        foreach ($hotels as $hotel) {
            foreach ($this->extractAttributeValues($hotel->getAttributes()) as $entry) {
                if ($entry['key'] === 'canManageAccess') {
                    $explicitPermissions['access'] = $this->normalizeBooleanPermission($entry['value']);
                    continue;
                }

                if ($entry['key'] === 'canManageHotelSettings') {
                    $explicitPermissions['hotelSettings'] = $this->normalizeBooleanPermission($entry['value']);
                    continue;
                }

                $normalizedRole = $this->normalizeRoleToken($entry['value']);
                if ($normalizedRole === '') {
                    continue;
                }

                if (in_array($normalizedRole, self::ACCESS_MANAGER_ROLES, true)) {
                    $rolePermissions['access'] = true;
                }

                if (in_array($normalizedRole, self::HOTEL_SETTINGS_MANAGER_ROLES, true)) {
                    $rolePermissions['hotelSettings'] = true;
                }

                if (in_array($normalizedRole, self::GLOBAL_MANAGEMENT_ROLES, true) || in_array($normalizedRole, self::ACCESS_ALL_HOTELS_GRANTS, true)) {
                    $accessAllHotels = true;
                }
            }
        }

        $canManageAccess = $explicitPermissions['access'] ?? $rolePermissions['access'];
        $canManageHotelSettings = $explicitPermissions['hotelSettings'] ?? $rolePermissions['hotelSettings'];

        $roles = [];
        if ($canManageAccess) {
            $roles[] = 'ROLE_ACCESS_MANAGER';
        }
        if ($canManageHotelSettings) {
            $roles[] = 'ROLE_HOTEL_SETTINGS_MANAGER';
        }

        return [
            'roles' => $roles,
            'accessAllHotels' => $accessAllHotels,
        ];
    }

    private function fetchConnectorAuthorizationContext(string $accessToken, string $userIdentifier): ?ExternalAuthorizationContext
    {
        try {
            return $this->connectorClient->fetchAuthorizationContext($accessToken);
        } catch (ExternalAuthenticationException | ExternalConnectorException $exception) {
            if ($this->logger !== null) {
                $this->logger->warning('Unable to load external authorization context, falling back to hotel payload authorization.', [
                    'user' => $userIdentifier,
                    'message' => $exception->getMessage(),
                ]);
            }

            return null;
        }
    }

    /**
     * @return list<ExternalHotelAccess>
     */
    private function loadPersistedHotels(): array
    {
        $hotels = [];
        foreach ($this->hotelRepository->findAll() as $hotel) {
            $hotels[] = new ExternalHotelAccess(
                $hotel->getExternalHotelId(),
                $hotel->getName()
            );
        }

        return $hotels;
    }

    /**
     * @return list<array{key: string, value: mixed}>
     */
    private function extractAttributeValues(array $attributes): array
    {
        $values = [];

        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                if (is_array($value)) {
                    $values = array_merge($values, $this->extractAttributeValues($value));
                }

                continue;
            }

            $normalizedKey = $this->normalizeAttributeKey($key);
            $explicitPermissionKey = self::EXPLICIT_PERMISSION_KEYS[$normalizedKey] ?? null;
            if ($explicitPermissionKey !== null) {
                $values[] = ['key' => $explicitPermissionKey, 'value' => $value];
            }

            if (in_array($normalizedKey, self::ROLE_KEYS, true)) {
                $values = array_merge($values, $this->extractRoleEntries($value));
                continue;
            }

            if (is_array($value)) {
                $values = array_merge($values, $this->extractAttributeValues($value));
            }
        }

        return $values;
    }

    /**
     * @return list<array{key: string, value: mixed}>
     */
    private function extractRoleEntries(mixed $value): array
    {
        if (is_string($value)) {
            return [['key' => 'roles', 'value' => $value]];
        }

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $nestedKey => $nestedValue) {
            if (is_int($nestedKey)) {
                $values = array_merge($values, $this->extractRoleEntries($nestedValue));
                continue;
            }

            if (!is_string($nestedKey)) {
                continue;
            }

            $normalizedKey = $this->normalizeAttributeKey($nestedKey);
            $explicitPermissionKey = self::EXPLICIT_PERMISSION_KEYS[$normalizedKey] ?? null;
            if ($explicitPermissionKey !== null) {
                $values[] = ['key' => $explicitPermissionKey, 'value' => $nestedValue];
                continue;
            }

            if (in_array($normalizedKey, self::ROLE_KEYS, true) || in_array($normalizedKey, self::ROLE_VALUE_KEYS, true)) {
                $values = array_merge($values, $this->extractRoleEntries($nestedValue));
                continue;
            }

            if (is_array($nestedValue)) {
                $values = array_merge($values, $this->extractRoleEntries($nestedValue));
            }
        }

        return $values;
    }

    private function normalizeAttributeKey(string $key): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '', $key);
        if (!is_string($normalized)) {
            return '';
        }

        return strtolower($normalized);
    }

    private function normalizeRoleToken(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalized = strtoupper(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), " \t\n\r\0\x0B_"));
        $normalized = preg_replace('/_+/', '_', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        if (!str_starts_with($normalized, 'ROLE_')) {
            $normalized = 'ROLE_' . $normalized;
        }

        return $normalized;
    }

    private function normalizeBooleanPermission(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param list<ExternalHotelAccess> $hotels
     * @param array{roles: list<string>, accessAllHotels: bool} $authorization
     */
    private function logUnresolvedAuthorization(
        string $userIdentifier,
        array $hotels,
        array $authorization,
        ?ExternalAuthorizationContext $connectorAuthorization = null
    ): void
    {
        if ($this->logger === null || $authorization['roles'] !== [] || $authorization['accessAllHotels']) {
            return;
        }

        $normalizedGrants = [];
        $explicitPermissions = [];

        foreach ($hotels as $hotel) {
            foreach ($this->extractAttributeValues($hotel->getAttributes()) as $entry) {
                if (in_array($entry['key'], ['canManageAccess', 'canManageHotelSettings'], true)) {
                    $explicitPermissions[$entry['key']][] = $entry['value'];
                    continue;
                }

                $normalizedRole = $this->normalizeRoleToken($entry['value']);
                if ($normalizedRole !== '') {
                    $normalizedGrants[$normalizedRole] = true;
                }
            }
        }

        $firstHotel = $hotels[0] ?? null;
        $firstHotelAttributes = $firstHotel?->getAttributes() ?? [];

        $this->logger->warning('Unable to resolve application authorization from accessible hotels.', [
            'user' => $userIdentifier,
            'hotelCount' => count($hotels),
            'resolvedRoles' => $authorization['roles'],
            'accessAllHotels' => $authorization['accessAllHotels'],
            'connectorRoles' => $connectorAuthorization?->getRoles() ?? [],
            'connectorAccessAllHotels' => $connectorAuthorization?->canAccessAllHotels() ?? false,
            'explicitPermissions' => array_map(
                static fn (array $values): array => array_values(array_unique(array_map(
                    static fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : get_debug_type($value),
                    $values
                ))),
                $explicitPermissions
            ),
            'normalizedGrants' => array_slice(array_keys($normalizedGrants), 0, 50),
            'firstHotelId' => $firstHotel?->getExternalHotelId(),
            'firstHotelAttributeKeys' => array_keys($firstHotelAttributes),
        ]);
    }
}
