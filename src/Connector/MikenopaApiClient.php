<?php

namespace App\Connector;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MikenopaApiClient implements ExternalConnectorClientInterface
{
    private const USER_ROLES_PATH = '/auth/roles/user';
    private const USER_PERMISSIONS_SUMMARY_PATH = '/auth/roles-permissions/user/aggregated';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(MIKENOPA_API_BASE_URL)%')]
        private string $baseUrl,
        #[Autowire('%env(MIKENOPA_OAUTH_TOKEN_PATH)%')]
        private string $tokenPath,
        #[Autowire('%env(MIKENOPA_HOTELS_PATH)%')]
        private string $hotelsPath,
        #[Autowire('%env(MIKENOPA_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(MIKENOPA_CLIENT_SECRET)%')]
        private string $clientSecret,
        #[Autowire('%env(default::MIKENOPA_SCOPE)%')]
        private ?string $scope = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function exchangePasswordForToken(string $username, string $password): ExternalOauthToken
    {
        return $this->requestOauthToken([
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $username,
            'password' => $password,
            'scope' => $this->normalizeOptionalString($this->scope),
        ]);
    }

    public function refreshAccessToken(string $refreshToken): ExternalOauthToken
    {
        return $this->requestOauthToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'scope' => $this->normalizeOptionalString($this->scope),
        ]);
    }

    public function fetchAccessibleHotels(string $accessToken): array
    {
        $url = $this->buildUrl($this->hotelsPath);
        $visitedUrls = [];
        $hotelsByExternalId = [];

        while ($url !== null) {
            if (isset($visitedUrls[$url])) {
                break;
            }

            $visitedUrls[$url] = true;

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                ]);
            } catch (TransportExceptionInterface $exception) {
                throw new ExternalConnectorException('Unable to reach the hotel endpoint.', 0, $exception);
            }

            $payload = $this->decodeResponse($response);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new ExternalAuthenticationException($this->extractErrorMessage($payload, 'The access token is not valid.'));
            }

            if ($statusCode >= 400) {
                throw new ExternalConnectorException($this->extractErrorMessage($payload, 'Unable to load accessible hotels.'));
            }

            if (count($visitedUrls) === 1) {
                $this->logHotelsPayloadShape($payload, $url);
            }

            foreach ($this->parseHotels($payload) as $hotel) {
                $hotelsByExternalId[$hotel->getExternalHotelId()] = $hotel;
            }

            $url = $this->resolveNextHotelsPageUrl($payload, $url);
        }

        return array_values($hotelsByExternalId);
    }

    public function fetchAuthorizationContext(string $accessToken): ExternalAuthorizationContext
    {
        $rolesPayload = $this->requestAuthorizedPayload(
            $accessToken,
            $this->buildUrl(self::USER_ROLES_PATH),
            'Unable to load the external user roles.'
        );
        $permissionsPayload = $this->requestAuthorizedPayload(
            $accessToken,
            $this->buildUrl(self::USER_PERMISSIONS_SUMMARY_PATH),
            'Unable to load the external user permissions.'
        );

        return new ExternalAuthorizationContext(
            $this->parseAuthorizationRoles($rolesPayload),
            $this->extractManageAllHotelsFlag($permissionsPayload)
        );
    }

    private function requestOauthToken(array $parameters): ExternalOauthToken
    {
        try {
            $response = $this->httpClient->request('POST', $this->buildUrl($this->tokenPath), [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'body' => array_filter(
                    $parameters,
                    static fn (mixed $value): bool => $value !== null && $value !== ''
                ),
            ]);
        } catch (TransportExceptionInterface $exception) {
            throw new ExternalConnectorException('Unable to reach the OAuth token endpoint.', 0, $exception);
        }

        $payload = $this->decodeResponse($response);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 400 || $statusCode === 401) {
            $error = $this->normalizeOptionalString($payload['error'] ?? null);
            $message = $this->extractErrorMessage($payload, 'Authentication against the external connector failed.');

            if ($error === 'invalid_grant') {
                throw new ExternalAuthenticationException($message);
            }

            if ($error === 'invalid_client') {
                throw new ExternalConnectorException('The connector client credentials are invalid.');
            }
        }

        if ($statusCode >= 400) {
            throw new ExternalConnectorException($this->extractErrorMessage($payload, 'Authentication against the external connector failed.'));
        }

        $accessToken = $this->normalizeOptionalString($payload['access_token'] ?? null);
        if ($accessToken === null) {
            throw new ExternalConnectorException('The OAuth token response does not contain an access token.');
        }

        $expiresAt = null;
        $expiresIn = $payload['expires_in'] ?? null;
        if (is_numeric($expiresIn)) {
            $seconds = (int) $expiresIn;
            if ($seconds > 0) {
                $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $seconds));
            }
        }

        $scopes = [];
        $scope = $payload['scope'] ?? null;
        if (is_string($scope) && trim($scope) !== '') {
            $scopes = array_values(array_filter(array_map('trim', preg_split('/\s+/', trim($scope)) ?: [])));
        }

        return new ExternalOauthToken(
            $accessToken,
            $this->normalizeOptionalString($payload['refresh_token'] ?? null),
            $expiresAt,
            $scopes
        );
    }

    private function requestAuthorizedPayload(string $accessToken, string $url, string $failureMessage): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
        } catch (TransportExceptionInterface $exception) {
            throw new ExternalConnectorException($failureMessage, 0, $exception);
        }

        $payload = $this->decodeResponse($response);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw new ExternalAuthenticationException($this->extractErrorMessage($payload, 'The access token is not valid.'));
        }

        if ($statusCode >= 400) {
            throw new ExternalConnectorException($this->extractErrorMessage($payload, $failureMessage));
        }

        return $payload;
    }

    private function decodeResponse(ResponseInterface $response): array
    {
        try {
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new ExternalConnectorException('Unable to read the connector response.', 0, $exception);
        }

        if ($content === '') {
            return [];
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ExternalConnectorException('The connector response is not valid JSON.', 0, $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    private function extractErrorMessage(array $payload, string $fallback): string
    {
        $message = $this->normalizeOptionalString($payload['error_description'] ?? null);
        if ($message !== null) {
            return $message;
        }

        $message = $this->normalizeOptionalString($payload['message'] ?? null);
        if ($message !== null) {
            return $message;
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && isset($payload['errors'][0]) && is_array($payload['errors'][0])) {
            $detail = $this->normalizeOptionalString($payload['errors'][0]['detail'] ?? null);
            if ($detail !== null) {
                return $detail;
            }
        }

        return $fallback;
    }

    /**
     * @return list<ExternalHotelAccess>
     */
    private function parseHotels(array $payload): array
    {
        $items = $payload['data'] ?? $payload['hotels'] ?? $payload['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        if (!array_is_list($items)) {
            $items = [$items];
        }

        $hotels = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $attributes = isset($item['attributes']) && is_array($item['attributes']) ? $item['attributes'] : [];
            $externalHotelId = $this->normalizeOptionalString(
                $item['externalHotelId']
                ?? $item['hotelId']
                ?? $attributes['externalHotelId']
                ?? $attributes['hotelId']
                ?? $item['id']
                ?? $attributes['id']
                ?? null
            );

            if ($externalHotelId === null) {
                continue;
            }

            $name = $this->normalizeOptionalString(
                $item['name']
                ?? $attributes['name']
                ?? $attributes['hotelName']
                ?? $attributes['label']
                ?? null
            );

            $hotels[] = new ExternalHotelAccess(
                $externalHotelId,
                $name,
                $this->normalizeHotelAttributes($item, $attributes)
            );
        }

        return $hotels;
    }

    /**
     * @return list<string>
     */
    private function parseAuthorizationRoles(array $payload): array
    {
        $data = $payload['data'] ?? $payload['roles'] ?? [];
        $roles = $this->flattenStringValues($data);

        return array_values(array_unique(array_filter($roles, static fn (string $role): bool => $role !== '')));
    }

    private function extractManageAllHotelsFlag(array $payload): bool
    {
        $data = $payload['data'] ?? $payload['permissions'] ?? [];
        if (!is_array($data)) {
            return false;
        }

        foreach (['manage_all_hotels', 'manageAllHotels', 'access_all_hotels', 'accessAllHotels'] as $key) {
            if ($this->containsTruthyValue($data[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function flattenStringValues(mixed $value): array
    {
        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? [] : [$normalized];
        }

        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $nestedValue) {
            $values = array_merge($values, $this->flattenStringValues($nestedValue));
        }

        return $values;
    }

    private function containsTruthyValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $nestedValue) {
            if ($this->containsTruthyValue($nestedValue)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHotelAttributes(array $item, array $attributes): array
    {
        unset($item['attributes']);

        return array_merge($attributes, $item);
    }

    private function resolveNextHotelsPageUrl(array $payload, string $currentUrl): ?string
    {
        foreach ([
            $payload['next'] ?? null,
            $payload['nextPageUrl'] ?? null,
            $payload['next_page_url'] ?? null,
            $this->extractNextPageLink($payload['links'] ?? null),
            $this->extractNextPageLink($payload['pagination'] ?? null),
            $this->extractNextPageLink($payload['meta'] ?? null),
        ] as $candidate) {
            $resolved = $this->normalizePaginationUrl($candidate, $currentUrl);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->buildNextPageUrlFromMetadata($payload, $currentUrl);
    }

    private function extractNextPageLink(mixed $container): ?string
    {
        if (!is_array($container)) {
            return null;
        }

        foreach (['next', 'nextPage', 'next_page', 'nextPageUrl', 'next_page_url'] as $key) {
            $value = $container[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                foreach (['href', 'url', 'link'] as $nestedKey) {
                    $nestedValue = $value[$nestedKey] ?? null;
                    if (is_string($nestedValue) && trim($nestedValue) !== '') {
                        return trim($nestedValue);
                    }
                }
            }
        }

        return null;
    }

    private function buildNextPageUrlFromMetadata(array $payload, string $currentUrl): ?string
    {
        $metadataContainers = [
            $payload['meta']['pager'] ?? null,
            $payload['meta'] ?? null,
            $payload['pagination']['pager'] ?? null,
            $payload['pagination'] ?? null,
            $payload['pager'] ?? null,
            $payload,
        ];

        foreach ($metadataContainers as $container) {
            if (!is_array($container)) {
                continue;
            }

            $currentPage = $this->extractIntegerValue($container, [
                'current_page',
                'currentPage',
                'page',
                'pageNumber',
                'page_number',
            ]);
            $nextPage = $this->extractIntegerValue($container, [
                'next_page',
                'nextPage',
                'nextPageNumber',
                'next_page_number',
            ]);
            $lastPage = $this->extractIntegerValue($container, [
                'last_page',
                'lastPage',
                'pageCount',
                'page_count',
                'totalPages',
                'total_pages',
                'pages',
            ]);

            if ($nextPage !== null && ($currentPage === null || $nextPage > $currentPage)) {
                return $this->withPageQuery($currentUrl, $nextPage);
            }

            if ($currentPage !== null && $lastPage !== null && $currentPage < $lastPage) {
                return $this->withPageQuery($currentUrl, $currentPage + 1);
            }
        }

        return null;
    }

    private function extractIntegerValue(array $container, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $container[$key] ?? null;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function withPageQuery(string $currentUrl, int $page): string
    {
        $parts = parse_url($currentUrl);
        if ($parts === false) {
            return $currentUrl;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        if (isset($query['page']) && is_array($query['page'])) {
            $query['page']['number'] = $page;
        } elseif (array_key_exists('page[number]', $query)) {
            $query['page[number]'] = $page;
        } else {
            $query['page'] = $page;
        }

        return $this->buildResolvedUrl($parts, http_build_query($query));
    }

    private function normalizePaginationUrl(mixed $candidate, string $currentUrl): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        if ($candidate === '' || strtolower($candidate) === 'null') {
            return null;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return $this->buildUrl($candidate);
        }

        $parts = parse_url($currentUrl);
        if ($parts === false) {
            return null;
        }

        if (str_starts_with($candidate, '?')) {
            return $this->buildResolvedUrl($parts, ltrim($candidate, '?'));
        }

        $basePath = $parts['path'] ?? '/';
        $directory = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';

        return $this->buildResolvedUrl([
            'scheme' => $parts['scheme'] ?? null,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'path' => $directory . ltrim($candidate, '/'),
        ]);
    }

    /**
     * @param array{scheme?: string|null, host?: string|null, port?: int|null, path?: string|null} $parts
     */
    private function buildResolvedUrl(array $parts, ?string $query = null): string
    {
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';

        $url = '';
        if (is_string($scheme) && $scheme !== '' && is_string($host) && $host !== '') {
            $url .= $scheme . '://' . $host;
            if (is_int($port)) {
                $url .= ':' . $port;
            }
        }

        $url .= $path;

        if (is_string($query) && $query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    private function logHotelsPayloadShape(array $payload, string $url): void
    {
        if ($this->logger === null) {
            return;
        }

        $items = $payload['data'] ?? $payload['hotels'] ?? $payload['items'] ?? [];
        if (is_array($items) && !array_is_list($items)) {
            $items = [$items];
        }

        $firstItem = (is_array($items) && isset($items[0]) && is_array($items[0])) ? $items[0] : [];
        $firstAttributes = isset($firstItem['attributes']) && is_array($firstItem['attributes']) ? $firstItem['attributes'] : [];
        $topLevelKeys = array_keys($payload);
        $topLevelAuthKeys = array_values(array_filter($topLevelKeys, static function (mixed $key): bool {
            if (!is_string($key)) {
                return false;
            }

            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $key));

            return in_array($normalized, [
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
                'canmanageaccess',
                'canmanagehotelsettings',
            ], true);
        }));

        $this->logger->info('Fetched hotels payload shape.', [
            'url' => $url,
            'topLevelKeys' => $topLevelKeys,
            'topLevelAuthKeys' => $topLevelAuthKeys,
            'metaKeys' => isset($payload['meta']) && is_array($payload['meta']) ? array_keys($payload['meta']) : [],
            'paginationKeys' => isset($payload['pagination']) && is_array($payload['pagination']) ? array_keys($payload['pagination']) : [],
            'linksKeys' => isset($payload['links']) && is_array($payload['links']) ? array_keys($payload['links']) : [],
            'firstItemKeys' => array_keys($firstItem),
            'firstItemAttributeKeys' => array_keys($firstAttributes),
        ]);
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
