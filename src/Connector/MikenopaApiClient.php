<?php

namespace App\Connector;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MikenopaApiClient implements ExternalConnectorClientInterface
{
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
        private ?string $scope = null
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
        try {
            $response = $this->httpClient->request('GET', $this->buildUrl($this->hotelsPath), [
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

        return $this->parseHotels($payload);
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

    private function normalizeHotelAttributes(array $item, array $attributes): array
    {
        unset($item['attributes']);

        return array_merge($attributes, $item);
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
