<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET|HEAD /healthz — readiness with degraded mode (docs/conception.md §4.3, §5.10, §9.1).
 */
final class HealthEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
        SqliteTestDatabase::clear($this->connection);
    }

    public function testOkWhenStatisticsReadable(): void
    {
        $this->client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseFormatSame('json');
        self::assertSame(
            ['status' => 'ok', 'checks' => ['statistics' => 'ok']],
            $this->jsonObject(),
        );
    }

    public function testDegradedWhenStorageUnavailable(): void
    {
        // HealthController uses DBAL only — do not swap RequestStatisticsStore (port is invisible here).
        // Replace the connection before it is fetched so the container accepts set().
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();

        $broken = $this->createStub(Connection::class);
        $broken->method('executeQuery')->willThrowException(
            new class('statistics storage unavailable for health check') extends \RuntimeException implements DbalException {},
        );
        static::getContainer()->set('doctrine.dbal.default_connection', $broken);

        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $content = $client->getResponse()->getContent();
        if (!\is_string($content)) {
            self::fail('Response content must be a string.');
        }
        self::assertSame(
            ['status' => 'degraded', 'checks' => ['statistics' => 'unavailable']],
            json_decode($content, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testHeadReturnsEmptyBody(): void
    {
        $this->client->request('HEAD', '/healthz');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(): array
    {
        $content = $this->client->getResponse()->getContent();
        if (!\is_string($content)) {
            self::fail('Response content must be a string.');
        }
        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail($e->getMessage());
        }
        if (!\is_array($decoded) || array_is_list($decoded)) {
            self::fail('Expected a JSON object.');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                self::fail('Expected string object keys.');
            }
            $object[$key] = $value;
        }

        return $object;
    }
}
