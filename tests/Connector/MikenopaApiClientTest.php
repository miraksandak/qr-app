<?php

namespace App\Tests\Connector;

use App\Connector\MikenopaApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MikenopaApiClientTest extends TestCase
{
    public function testFetchAccessibleHotelsFollowsNextLink(): void
    {
        $requestUrls = [];
        $responses = [
            new MockResponse(json_encode([
                'data' => [
                    ['id' => 'hotel-001', 'name' => 'Hotel 001'],
                ],
                'links' => [
                    'next' => 'https://api.example.test/hotels?page=2',
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    ['id' => 'hotel-002', 'name' => 'Hotel 002'],
                ],
                'links' => [
                    'next' => null,
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $client = $this->createClient($responses, $requestUrls);

        $hotels = $client->fetchAccessibleHotels('access-token');

        $this->assertCount(2, $hotels);
        $this->assertSame('hotel-001', $hotels[0]->getExternalHotelId());
        $this->assertSame('hotel-002', $hotels[1]->getExternalHotelId());
        $this->assertSame([
            'https://api.example.test/hotels',
            'https://api.example.test/hotels?page=2',
        ], $requestUrls);
    }

    public function testFetchAccessibleHotelsBuildsNextPageFromPaginationMetadata(): void
    {
        $requestUrls = [];
        $responses = [
            new MockResponse(json_encode([
                'data' => [
                    ['id' => 'hotel-001', 'name' => 'Hotel 001'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    ['id' => 'hotel-002', 'name' => 'Hotel 002'],
                ],
                'meta' => [
                    'current_page' => 2,
                    'last_page' => 2,
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $client = $this->createClient($responses, $requestUrls);

        $hotels = $client->fetchAccessibleHotels('access-token');

        $this->assertCount(2, $hotels);
        $this->assertSame([
            'https://api.example.test/hotels',
            'https://api.example.test/hotels?page=2',
        ], $requestUrls);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string> $requestUrls
     */
    private function createClient(array $responses, array &$requestUrls): MikenopaApiClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$requestUrls) {
            $requestUrls[] = $url;

            return array_shift($responses);
        });

        return new MikenopaApiClient(
            $httpClient,
            'https://api.example.test',
            '/oauth/token',
            '/hotels',
            'client-id',
            'client-secret'
        );
    }
}
