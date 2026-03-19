<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
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

    public function testHotelUpsertCreatesAndReturnsConfiguration(): void
    {
        $client = static::createClient();
        $client->request(
            'PUT',
            '/api/hotels/f7768642-f895-11e8-99a6-005056a52682',
            server: [
                'HTTP_X-API-Key' => 'dev-local-key',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'name' => 'AC Hotel Venezia',
                'configuration' => [
                    'supportText' => 'Need help? Contact reception.',
                    'portalUrl' => 'https://portal.example.test',
                    'proxyApiBaseUrl' => 'https://proxy.example.test',
                    'datacenterId' => 'central',
                    'primaryAuthMode' => 'accessCode',
                    'device' => [
                        'default' => 'ios',
                        'available' => ['android', 'ios'],
                    ],
                    'ssids' => [
                        ['name' => 'AC-Venezia', 'usage' => 'pms'],
                        ['name' => 'AC-Venezia-Code', 'usage' => 'ac'],
                    ],
                    'upgrade' => [
                        'enabled' => true,
                        'url' => 'https://upgrade.example.test',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('f7768642-f895-11e8-99a6-005056a52682', $payload['hotel']['externalHotelId']);
        $this->assertSame('AC Hotel Venezia', $payload['hotel']['name']);
        $this->assertSame('accessCode', $payload['configuration']['primaryAuthMode']);
        $this->assertSame('ios', $payload['configuration']['device']['default']);
        $this->assertSame(['android', 'ios'], $payload['configuration']['device']['available']);
        $this->assertCount(2, $payload['configuration']['ssids']);
        $this->assertTrue($payload['configuration']['upgrade']['enabled']);
    }

    public function testHotelUpsertUpdatesExistingConfiguration(): void
    {
        $client = static::createClient();
        $headers = [
            'HTTP_X-API-Key' => 'dev-local-key',
            'CONTENT_TYPE' => 'application/json',
        ];

        $client->request(
            'PUT',
            '/api/hotels/f7768642-f895-11e8-99a6-005056a52682',
            server: $headers,
            content: json_encode([
                'name' => 'AC Hotel Venezia',
                'configuration' => [
                    'supportText' => 'Initial support',
                    'device' => [
                        'available' => ['android', 'generic'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);

        $client->request(
            'PUT',
            '/api/hotels/f7768642-f895-11e8-99a6-005056a52682',
            server: $headers,
            content: json_encode([
                'name' => 'AC Hotel Venezia Updated',
                'configuration' => [
                    'supportText' => 'Updated support',
                    'device' => [
                        'default' => 'android',
                    ],
                    'upgrade' => [
                        'enabled' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('AC Hotel Venezia Updated', $payload['hotel']['name']);
        $this->assertSame('Updated support', $payload['configuration']['supportText']);
        $this->assertSame(['android', 'generic'], $payload['configuration']['device']['available']);
        $this->assertSame('android', $payload['configuration']['device']['default']);
        $this->assertFalse($payload['configuration']['upgrade']['enabled']);
    }

    public function testHotelFetchRequiresApiKey(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/hotels/f7768642-f895-11e8-99a6-005056a52682');

        $this->assertResponseStatusCodeSame(401);
    }
}
