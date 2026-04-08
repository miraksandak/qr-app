<?php

namespace App\Connector;

interface ExternalConnectorClientInterface
{
    public function exchangePasswordForToken(string $username, string $password): ExternalOauthToken;

    public function refreshAccessToken(string $refreshToken): ExternalOauthToken;

    public function fetchAuthorizationContext(string $accessToken): ExternalAuthorizationContext;

    /**
     * @return list<ExternalHotelAccess>
     */
    public function fetchAccessibleHotels(string $accessToken): array;
}
