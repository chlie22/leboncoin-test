<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application logs must never contain str1/str2; X-Request-Id is correlated (docs/conception.md §8.1, §9.1).
 */
final class ApplicationLoggingPrivacyTest extends WebTestCase
{
    private const MARKER1 = 'MARK_STR1_PRIVACY_9';
    private const MARKER2 = 'MARK_STR2_PRIVACY_9';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        SqliteTestDatabase::clear($connection);
    }

    public function testMarkersAbsentFromApplicationLogsOnNotFound(): void
    {
        $this->client->request(
            'GET',
            '/no-such-route?str1='.self::MARKER1.'&str2='.self::MARKER2,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertMarkersAbsentFromLogs();
    }

    public function testMarkersAbsentFromApplicationLogsOnBadRequest(): void
    {
        $this->client->request(
            'GET',
            '/v1/fizzbuzz?int1=abc&int2=5&limit=15&str1='.self::MARKER1.'&str2='.self::MARKER2,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertMarkersAbsentFromLogs();
    }

    public function testRequestIdAppearsOnDegradedWarning(): void
    {
        $this->client->disableReboot();
        $down = new InMemoryRequestStatisticsStore(5);
        $down->breakDown();
        static::getContainer()->set(RequestStatisticsStore::class, $down);

        $this->client->request(
            'GET',
            '/v1/fizzbuzz?int1=3&int2=5&limit=15&str1=fizz&str2=buzz',
            server: ['HTTP_X_REQUEST_ID' => 'corr-req-9'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $requestIds = [];
        foreach ($this->testHandler()->getRecords() as $record) {
            if (isset($record->extra['request_id'])) {
                $requestIds[] = $record->extra['request_id'];
            }
        }
        self::assertContains('corr-req-9', $requestIds);
    }

    private function assertMarkersAbsentFromLogs(): void
    {
        $haystack = $this->stringifyLogStrings();
        self::assertStringNotContainsString(self::MARKER1, $haystack);
        self::assertStringNotContainsString(self::MARKER2, $haystack);
    }

    private function stringifyLogStrings(): string
    {
        $parts = [];
        foreach ($this->testHandler()->getRecords() as $record) {
            $parts[] = $record->message;
            $parts[] = $this->collectStrings($record->context);
            $parts[] = $this->collectStrings($record->extra);
        }

        return implode("\n", $parts);
    }

    private function collectStrings(mixed $data): string
    {
        if (\is_string($data) || \is_int($data) || \is_float($data) || \is_bool($data) || null === $data) {
            return (string) $data;
        }
        if ($data instanceof \Stringable) {
            return (string) $data;
        }
        if (!\is_array($data)) {
            return '';
        }

        $parts = [];
        foreach ($data as $value) {
            $parts[] = $this->collectStrings($value);
        }

        return implode("\n", $parts);
    }

    private function testHandler(): TestHandler
    {
        $handler = static::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
