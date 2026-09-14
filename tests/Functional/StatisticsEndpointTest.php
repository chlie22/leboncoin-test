<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional cases for GET|HEAD /v1/stats (docs/conception.md §3.4, §4.2, §5.10).
 */
final class StatisticsEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
        SqliteTestDatabase::clear($this->connection);
    }

    public function testEmptyWindow(): void
    {
        $this->client->request('GET', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([
            'request' => null,
            'hits' => 0,
            'window' => [
                'type' => 'last_requests',
                'size' => 5,
                'count' => 0,
            ],
        ], $this->json());
    }

    public function testAfterCountedCallsReturnsWinner(): void
    {
        $this->hitFizzBuzz('int1=3&int2=5&limit=15&str1=fizz&str2=buzz');
        $this->hitFizzBuzz('int1=3&int2=5&limit=15&str1=fizz&str2=buzz');
        $this->hitFizzBuzz('int1=2&int2=4&limit=8&str1=a&str2=b');

        $this->client->request('GET', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([
            'request' => [
                'int1' => 3,
                'int2' => 5,
                'limit' => 15,
                'str1' => 'fizz',
                'str2' => 'buzz',
            ],
            'hits' => 2,
            'window' => [
                'type' => 'last_requests',
                'size' => 5,
                'count' => 3,
            ],
        ], $this->json());
    }

    public function testTieBreakReturnsOldestCombination(): void
    {
        // A, B, B, A with N≥4 → both have 2 hits; A wins (smallest id / longest present).
        $this->hitFizzBuzz('int1=1&int2=2&limit=3&str1=A&str2=x');
        $this->hitFizzBuzz('int1=1&int2=2&limit=3&str1=B&str2=x');
        $this->hitFizzBuzz('int1=1&int2=2&limit=3&str1=B&str2=x');
        $this->hitFizzBuzz('int1=1&int2=2&limit=3&str1=A&str2=x');

        $this->client->request('GET', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->json();
        $request = $body['request'] ?? null;
        if (!\is_array($request)) {
            self::fail('Expected a request object.');
        }
        self::assertSame('A', $request['str1'] ?? null);
        self::assertSame(2, $body['hits'] ?? null);
        $window = $body['window'] ?? null;
        if (!\is_array($window)) {
            self::fail('Expected a window object.');
        }
        self::assertSame(4, $window['count'] ?? null);
    }

    public function testExtraQueryParametersAreIgnored(): void
    {
        $this->hitFizzBuzz('int1=3&int2=5&limit=15&str1=fizz&str2=buzz');

        $this->client->request('GET', '/v1/stats?foo=bar&limit=1');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->json();
        $request = $body['request'] ?? null;
        if (!\is_array($request)) {
            self::fail('Expected a request object.');
        }
        self::assertSame(3, $request['int1'] ?? null);
        self::assertSame(1, $body['hits'] ?? null);
    }

    public function testHeadReturnsEmptyBodyWithoutChangingCount(): void
    {
        $this->hitFizzBuzz('int1=3&int2=5&limit=15&str1=fizz&str2=buzz');
        $before = \count(SqliteTestDatabase::snapshot($this->connection)['log']);

        $this->client->request('HEAD', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame($before, \count(SqliteTestDatabase::snapshot($this->connection)['log']));
    }

    public function testUnavailableStoreReturnsServiceUnavailable(): void
    {
        $down = new InMemoryRequestStatisticsStore(5);
        $down->breakDown();
        static::getContainer()->set(RequestStatisticsStore::class, $down);

        $this->client->request('GET', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        // detail depends on kernel.debug (exception message in test, status text in prod): not asserted here.
        self::assertSame(503, $this->json()['status'] ?? null);
    }

    public function testHeadWithUnavailableStoreReturnsServiceUnavailableWithoutBody(): void
    {
        $down = new InMemoryRequestStatisticsStore(5);
        $down->breakDown();
        static::getContainer()->set(RequestStatisticsStore::class, $down);

        $this->client->request('HEAD', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testDisallowedMethodReturnsProblemJson(): void
    {
        $this->client->request('POST', '/v1/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    private function hitFizzBuzz(string $query): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.$query);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
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
