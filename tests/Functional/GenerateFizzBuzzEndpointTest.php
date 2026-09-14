<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\Tests\Support\InMemoryRequestStatisticsStore;
use App\Tests\Support\OpenApiStringContract;
use App\Tests\Support\SqliteTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Functional matrix of docs/conception.md §3.3 for GET|HEAD /v1/fizzbuzz, plus 404/405, degraded mode and R12.
 */
final class GenerateFizzBuzzEndpointTest extends WebTestCase
{
    private const REQUIRED = 'This parameter is required and must not be empty.';
    private const TYPE_INT = 'This value should be of type int.';
    /** Array on a nullable DTO property: Symfony reports the union, not bare "int" (docs/conception.md §3.3). */
    private const TYPE_INT_NULLABLE = 'This value should be of type int|null.';
    private const TYPE_STRING_NULLABLE = 'This value should be of type null|string.';
    private const RANGE_INT = 'This value should be between 1 and 2147483647.';
    private const RANGE_LIMIT = 'This value should be between 1 and 10000.';
    private const CONTROL_CHARACTER = 'This value is not valid.';
    private const INVALID_UTF8 = 'This value does not match the expected UTF-8 charset.';
    private const TOO_LONG = 'This value is too long. It should have 50 characters or less.';

    private const HAPPY_QUERY = 'int1=3&int2=5&limit=15&str1=fizz&str2=buzz';
    /** @var list<string> */
    private const HAPPY_BODY = ['1', '2', 'fizz', '4', 'buzz', 'fizz', '7', '8', 'fizz', 'buzz', '11', 'fizz', '13', '14', 'fizzbuzz'];

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
        SqliteTestDatabase::clear($this->connection);
    }

    public function testHappyPathReturnsSequenceAndCountsOnce(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.self::HAPPY_QUERY);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseFormatSame('json');
        self::assertSame(self::HAPPY_BODY, $this->jsonList());
        self::assertSame(1, $this->windowCount());
    }

    public function testLeadingZeroIntIsAcceptedAndSameCombination(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=03&int2=5&limit=15&str1=fizz&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(self::HAPPY_BODY, $this->jsonList());
        self::assertSame(1, $this->windowCount());

        $this->client->request('GET', '/v1/fizzbuzz?'.self::HAPPY_QUERY);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(2, $this->windowCount());

        $stats = SqliteTestDatabase::snapshot($this->connection);
        self::assertCount(1, $stats['stat']);
        self::assertSame(2, $this->sqliteInt($stats['stat'][0]['hits']));
    }

    #[DataProvider('missingOrEmptyInts')]
    public function testMissingOrEmptyIntReturnsRequired(string $query, string $propertyPath): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.$query);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation($propertyPath, self::REQUIRED);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingOrEmptyInts(): iterable
    {
        yield 'int1 absent' => ['int2=5&limit=15&str1=fizz&str2=buzz', 'int1'];
        yield 'int1 empty' => ['int1=&int2=5&limit=15&str1=fizz&str2=buzz', 'int1'];
        yield 'int2 absent' => ['int1=3&limit=15&str1=fizz&str2=buzz', 'int2'];
        yield 'limit empty' => ['int1=3&int2=5&limit=&str1=fizz&str2=buzz', 'limit'];
    }

    /**
     * IgnoreDeprecations: with collect_denormalization_errors, Symfony still passes the raw float-string
     * into the typed ?int constructor parameter after recording the conversion error (PHP 8.5).
     */
    #[IgnoreDeprecations]
    #[DataProvider('intConversionFailures')]
    public function testIntConversionFailure(string $queryFragment, string $expectedTitle): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.$queryFragment.'&int2=5&limit=15&str1=fizz&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation('int1', $expectedTitle);
        self::assertCount(1, $this->violations());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function intConversionFailures(): iterable
    {
        yield 'abc' => ['int1=abc', self::TYPE_INT];
        yield 'decimal' => ['int1=3.5', self::TYPE_INT];
        yield 'plus sign' => ['int1=+3', self::TYPE_INT];
        yield 'leading space' => ['int1=%203', self::TYPE_INT];
        yield 'scientific' => ['int1=3e2', self::TYPE_INT];
        yield 'array' => ['int1[]=3', self::TYPE_INT_NULLABLE];
    }

    #[DataProvider('intRangeFailures')]
    public function testIntRangeFailure(string $query, string $propertyPath, string $expectedTitle): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.$query);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation($propertyPath, $expectedTitle);
        self::assertCount(1, $this->violations());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function intRangeFailures(): iterable
    {
        yield 'negative' => ['int1=-3&int2=5&limit=15&str1=fizz&str2=buzz', 'int1', self::RANGE_INT];
        yield 'zero' => ['int1=0&int2=5&limit=15&str1=fizz&str2=buzz', 'int1', self::RANGE_INT];
        yield 'limit zero' => ['int1=3&int2=5&limit=0&str1=fizz&str2=buzz', 'limit', self::RANGE_LIMIT];
        yield 'limit too high' => ['int1=3&int2=5&limit=10001&str1=fizz&str2=buzz', 'limit', self::RANGE_LIMIT];
        yield 'huge int truncated then rejected' => ['int1=99999999999999999999&int2=5&limit=15&str1=fizz&str2=buzz', 'int1', self::RANGE_INT];
    }

    public function testDuplicateQueryKeyLastWins(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=3&int1=4&int2=5&limit=15&str1=fizz&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->jsonList();
        self::assertSame('fizz', $body[3] ?? null);
        self::assertSame(1, $this->windowCount());

        $stats = SqliteTestDatabase::snapshot($this->connection);
        self::assertSame(4, $this->sqliteInt($stats['stat'][0]['int1']));
    }

    #[DataProvider('missingOrEmptyStrings')]
    public function testMissingOrEmptyStringReturnsRequired(string $query, string $propertyPath): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?'.$query);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation($propertyPath, self::REQUIRED);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingOrEmptyStrings(): iterable
    {
        yield 'str1 absent' => ['int1=3&int2=5&limit=15&str2=buzz', 'str1'];
        yield 'str1 empty' => ['int1=3&int2=5&limit=15&str1=&str2=buzz', 'str1'];
        yield 'str2 absent' => ['int1=3&int2=5&limit=15&str1=fizz', 'str2'];
        yield 'str2 empty' => ['int1=3&int2=5&limit=15&str1=fizz&str2=', 'str2'];
    }

    #[DataProvider('validEdgeStrings')]
    public function testValidEdgeStrings(string $str1QueryValue, string $expectedStr1): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=2&int2=5&limit=2&str1='.$str1QueryValue.'&str2=b');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['1', $expectedStr1], $this->jsonList());
        self::assertTrue(OpenApiStringContract::accepts($expectedStr1));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validEdgeStrings(): iterable
    {
        yield 'zero string' => ['0', '0'];
        yield 'space' => ['%20', ' '];
    }

    /**
     * Sequentially stops at the first failing constraint: exactly one violation per invalid string.
     */
    #[DataProvider('invalidStrings')]
    public function testInvalidStrings(string $str1QueryValue, string $decodedForOpenApi, string $expectedTitle): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=3&int2=5&limit=15&str1='.$str1QueryValue.'&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation('str1', $expectedTitle);
        self::assertCount(1, $this->violations());
        self::assertFalse(OpenApiStringContract::accepts($decodedForOpenApi));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'trailing newline' => ['fizz%0A', "fizz\n", self::CONTROL_CHARACTER];
        yield 'internal newline' => ['fi%0Azz', "fi\nzz", self::CONTROL_CHARACTER];
        yield 'carriage return' => ['%0D', "\r", self::CONTROL_CHARACTER];
        yield 'tab' => ['%09', "\t", self::CONTROL_CHARACTER];
        yield 'nul' => ['%00', "\0", self::CONTROL_CHARACTER];
        yield 'invalid utf8' => ['%FF', "\xFF", self::INVALID_UTF8];
        yield '51 code points' => [str_repeat('a', 51), str_repeat('a', 51), self::TOO_LONG];
    }

    public function testValidationProblemBodyMatchesContractExample(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=3&int2=5&limit=0&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        $body = $this->jsonObject();
        self::assertSame('https://symfony.com/errors/validation', $body['type'] ?? null);
        self::assertSame('Validation Failed', $body['title'] ?? null);
        self::assertSame(
            "limit: This value should be between 1 and 10000.\nstr1: This parameter is required and must not be empty.",
            $body['detail'] ?? null,
        );
        self::assertSame([
            ['propertyPath' => 'limit', 'title' => self::RANGE_LIMIT],
            ['propertyPath' => 'str1', 'title' => self::REQUIRED],
        ], $this->violations());
    }

    public function testStringArrayConversionFailure(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=3&int2=5&limit=15&str1[]=a&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        self::assertViolation('str1', self::TYPE_STRING_NULLABLE);
    }

    public function testNoParametersReturnsFiveViolations(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertProblemJson();
        $violations = $this->violations();
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation['propertyPath'];
            self::assertSame(self::REQUIRED, $violation['title']);
        }
        sort($paths);
        self::assertSame(['int1', 'int2', 'limit', 'str1', 'str2'], $paths);
    }

    public function testShuffledParameterOrderSameStatsKey(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?str2=buzz&limit=15&int1=3&str1=fizz&int2=5');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(self::HAPPY_BODY, $this->jsonList());

        $this->client->request('GET', '/v1/fizzbuzz?'.self::HAPPY_QUERY);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $stats = SqliteTestDatabase::snapshot($this->connection);
        self::assertCount(1, $stats['stat']);
        self::assertSame(2, $this->sqliteInt($stats['stat'][0]['hits']));
    }

    public function testEmojiIsNotEscapedInJson(): void
    {
        $this->client->request('GET', '/v1/fizzbuzz?int1=1&int2=2&limit=1&str1=%F0%9F%8D%95&str2=b');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('🍕', $content);
        self::assertStringNotContainsString('\ud83c', $content);
        self::assertStringNotContainsString('\\ud83c', $content);
    }

    public function testWorstCaseLimitKeepsResponseUnderBudget(): void
    {
        $fifty = rawurlencode(str_repeat('a', 50));
        $this->client->request('GET', '/v1/fizzbuzz?int1=1&int2=1&limit=10000&str1='.$fifty.'&str2='.$fifty);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertLessThan(6_100_000, \strlen($content));
    }

    public function testInvalidRequestIsNotCounted(): void
    {
        $before = $this->windowCount();
        $this->client->request('GET', '/v1/fizzbuzz?int1=abc&int2=5&limit=15&str1=fizz&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame($before, $this->windowCount());
    }

    public function testHeadValidReturnsEmptyBodyAndDoesNotCount(): void
    {
        $this->client->request('HEAD', '/v1/fizzbuzz?'.self::HAPPY_QUERY);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->windowCount());
    }

    public function testHeadInvalidReturnsEmptyBody(): void
    {
        $this->client->request('HEAD', '/v1/fizzbuzz?int1=abc&int2=5&limit=15&str1=fizz&str2=buzz');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->windowCount());
    }

    public function testDegradedModeStillReturnsSequenceWithoutCounting(): void
    {
        $down = new InMemoryRequestStatisticsStore(5);
        $down->breakDown();
        static::getContainer()->set(RequestStatisticsStore::class, $down);

        $this->client->request('GET', '/v1/fizzbuzz?'.self::HAPPY_QUERY);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(self::HAPPY_BODY, $this->jsonList());
        self::assertSame(0, $this->windowCount());
        self::assertSame(1, $down->recordAttempts());
    }

    public function testErrorAfterCommitStillCounts(): void
    {
        $this->client->disableReboot();
        $thrown = false;
        static::getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::RESPONSE,
            static function () use (&$thrown): void {
                if ($thrown) {
                    return;
                }
                $thrown = true;
                throw new \RuntimeException('Injected failure after commit (R12).');
            },
            -1024,
        );

        $this->client->request('GET', '/v1/fizzbuzz?'.self::HAPPY_QUERY);

        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        self::assertSame(1, $this->windowCount());
    }

    public function testUnknownRouteReturnsProblemJson(): void
    {
        $this->client->request('GET', '/no-such-route');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertProblemJson();
    }

    public function testDisallowedMethodReturnsProblemJson(): void
    {
        $this->client->request('POST', '/v1/fizzbuzz?'.self::HAPPY_QUERY);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertProblemJson();
    }

    /**
     * @return list<mixed>
     */
    private function jsonList(): array
    {
        $decoded = $this->decodeJson();
        if (!array_is_list($decoded)) {
            self::fail('Expected a JSON array.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(): array
    {
        $decoded = $this->decodeJson();
        if (array_is_list($decoded)) {
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

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJson(): array
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
        if (!\is_array($decoded)) {
            self::fail('JSON root must be an array or object.');
        }

        return $decoded;
    }

    private function windowCount(): int
    {
        return \count(SqliteTestDatabase::snapshot($this->connection)['log']);
    }

    private function sqliteInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        self::fail(\sprintf('Expected an integer column, got %s.', get_debug_type($value)));
    }

    /**
     * RFC 9457 envelope of docs/openapi.yaml Problem: type, title and status matching the HTTP status.
     */
    private function assertProblemJson(): void
    {
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = $this->jsonObject();
        self::assertIsString($body['type'] ?? null);
        self::assertIsString($body['title'] ?? null);
        self::assertSame($response->getStatusCode(), $body['status'] ?? null);
    }

    private function assertViolation(string $propertyPath, string $title): void
    {
        $violation = $this->violationFor($propertyPath);
        self::assertNotNull($violation, \sprintf('Expected a violation for "%s".', $propertyPath));
        self::assertSame($title, $violation['title']);
    }

    /**
     * @return list<array{propertyPath: string, title: string}>
     */
    private function violations(): array
    {
        $body = $this->jsonObject();
        $raw = $body['violations'] ?? null;
        if (!\is_array($raw)) {
            self::fail('Expected a violations list.');
        }

        $violations = [];
        foreach ($raw as $violation) {
            if (!\is_array($violation)) {
                self::fail('Each violation must be an object.');
            }
            $path = $violation['propertyPath'] ?? null;
            $title = $violation['title'] ?? null;
            if (!\is_string($path) || !\is_string($title)) {
                self::fail('Violation propertyPath and title must be strings.');
            }
            $violations[] = ['propertyPath' => $path, 'title' => $title];
        }

        return $violations;
    }

    /**
     * @return array{propertyPath: string, title: string}|null
     */
    private function violationFor(string $propertyPath): ?array
    {
        foreach ($this->violations() as $violation) {
            if ($violation['propertyPath'] === $propertyPath) {
                return $violation;
            }
        }

        return null;
    }
}
