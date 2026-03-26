<?php

namespace App\Tests\Controller;

use App\Connector\ExternalAuthenticationException;
use App\Connector\ExternalConnectorClientInterface;
use App\Connector\ExternalHotelAccess;
use App\Connector\ExternalOauthToken;
use App\Security\ExternalUser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HotelControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        self::ensureKernelShutdown();
    }

    public function testLoginLoadsAccessibleHotelsFromExternalConnector(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(ExternalConnectorClientInterface::class, new FakeExternalConnectorClient());

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Login')->form([
            '_username' => 'alice',
            '_password' => 'secret',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/');

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.page-head h2', 'alice');
        $this->assertSelectorExists('#hotel-selector option[value="hotel-alpha"]');
        $this->assertSelectorExists('#hotel-selector option[value="hotel-beta"]');
    }

    public function testSessionWorkflowCreatesDefaultConfigurationOnFirstManualCreation(): void
    {
        $client = $this->createLoggedInClient();

        $client->request('GET', '/app/api/hotels/hotel-alpha');
        $this->assertResponseIsSuccessful();

        $preview = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($preview['meta']['persisted']);
        $this->assertSame('Hotel Alpha', $preview['hotel']['name']);
        $this->assertSame('Need help? Contact reception.', $preview['configuration']['supportText']);
        $this->assertSame('/media/mik-logo.png', $preview['configuration']['logoUrl']);

        $client->request(
            'POST',
            '/app/api/manual',
            server: [
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'hotelExternalId' => 'hotel-alpha',
                'options' => [
                    'mode' => 'roomSurname',
                    'roomSurname' => [
                        'room' => '101',
                        'surname' => 'Doe',
                    ],
                    'freeAccess' => [
                        'enabled' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);

        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('hotel-alpha', $created['hotelExternalId']);
        $this->assertStringContainsString('/json/', $created['jsonUrl']);

        $client->request('GET', '/app/api/hotels/hotel-alpha');
        $this->assertResponseIsSuccessful();

        $stored = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($stored['meta']['persisted']);
        $this->assertSame('Need help? Contact reception.', $stored['configuration']['supportText']);
        $this->assertSame('/media/mik-logo.png', $stored['configuration']['logoUrl']);
    }

    public function testBearerTokenEndpointsWorkWithoutSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(ExternalConnectorClientInterface::class, new FakeExternalConnectorClient());

        $client->request('GET', '/api/hotels');
        $this->assertResponseStatusCodeSame(401);

        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer bearer-alice',
            'CONTENT_TYPE' => 'application/json',
        ];

        $client->request('GET', '/api/hotels', server: $headers);
        $this->assertResponseIsSuccessful();

        $hotels = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $hotels['data']);
        $this->assertSame('hotel-alpha', $hotels['data'][0]['externalHotelId']);
        $this->assertFalse($hotels['data'][0]['configurationExists']);

        $client->request(
            'POST',
            '/api/manual',
            server: $headers,
            content: json_encode([
                'hotelExternalId' => 'hotel-beta',
                'options' => [
                    'mode' => 'accessCode',
                    'accessCode' => [
                        'code' => 'ZXCV-9876',
                    ],
                    'freeAccess' => [
                        'enabled' => true,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/hotels/hotel-beta', server: $headers);
        $this->assertResponseIsSuccessful();

        $stored = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($stored['meta']['persisted']);
        $this->assertSame('Hotel Beta', $stored['hotel']['name']);
    }

    private function createLoggedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(ExternalConnectorClientInterface::class, new FakeExternalConnectorClient());

        $client->loginUser(new ExternalUser(
            'alice',
            'alice',
            new ExternalOauthToken('token-alice', 'refresh-alice', new \DateTimeImmutable('+2 hours')),
            [
                new ExternalHotelAccess('hotel-alpha', 'Hotel Alpha', ['region' => 'north']),
                new ExternalHotelAccess('hotel-beta', 'Hotel Beta', ['region' => 'south']),
            ],
            new \DateTimeImmutable('now')
        ), 'main');

        return $client;
    }
}

final class FakeExternalConnectorClient implements ExternalConnectorClientInterface
{
    public function exchangePasswordForToken(string $username, string $password): ExternalOauthToken
    {
        if ($username !== 'alice' || $password !== 'secret') {
            throw new ExternalAuthenticationException('Invalid credentials.');
        }

        return new ExternalOauthToken(
            'token-alice',
            'refresh-alice',
            new \DateTimeImmutable('+2 hours')
        );
    }

    public function refreshAccessToken(string $refreshToken): ExternalOauthToken
    {
        if ($refreshToken !== 'refresh-alice') {
            throw new ExternalAuthenticationException('Invalid refresh token.');
        }

        return new ExternalOauthToken(
            'token-alice',
            'refresh-alice',
            new \DateTimeImmutable('+2 hours')
        );
    }

    public function fetchAccessibleHotels(string $accessToken): array
    {
        if (!in_array($accessToken, ['token-alice', 'bearer-alice'], true)) {
            throw new ExternalAuthenticationException('Invalid access token.');
        }

        return [
            new ExternalHotelAccess('hotel-alpha', 'Hotel Alpha', ['region' => 'north']),
            new ExternalHotelAccess('hotel-beta', 'Hotel Beta', ['region' => 'south']),
        ];
    }
}
