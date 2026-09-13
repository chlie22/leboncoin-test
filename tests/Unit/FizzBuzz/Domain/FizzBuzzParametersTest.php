<?php

declare(strict_types=1);

namespace App\Tests\Unit\FizzBuzz\Domain;

use App\FizzBuzz\Domain\Exception\InvalidFizzBuzzParameters;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FizzBuzzParameters::class)]
#[CoversClass(InvalidFizzBuzzParameters::class)]
final class FizzBuzzParametersTest extends TestCase
{
    public function testKeepsTheFiveValues(): void
    {
        $parameters = new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz');

        self::assertSame(3, $parameters->int1);
        self::assertSame(5, $parameters->int2);
        self::assertSame(15, $parameters->limit);
        self::assertSame('fizz', $parameters->str1);
        self::assertSame('buzz', $parameters->str2);
    }

    public function testAcceptsOneForEachInteger(): void
    {
        $parameters = new FizzBuzzParameters(1, 1, 1, 'fizz', 'buzz');

        self::assertSame(1, $parameters->int1);
        self::assertSame(1, $parameters->int2);
        self::assertSame(1, $parameters->limit);
    }

    public function testLeavesHttpBoundsAndStringRulesToTheApi(): void
    {
        $parameters = new FizzBuzzParameters(\PHP_INT_MAX, \PHP_INT_MAX, 10_001, '', "fi\nzz");

        self::assertSame(10_001, $parameters->limit);
        self::assertSame('', $parameters->str1);
        self::assertSame("fi\nzz", $parameters->str2);
    }

    #[DataProvider('nonPositiveIntegers')]
    public function testRejectsNonPositiveIntegers(int $int1, int $int2, int $limit, string $message): void
    {
        $this->expectException(InvalidFizzBuzzParameters::class);
        $this->expectExceptionMessage($message);

        new FizzBuzzParameters($int1, $int2, $limit, 'fizz', 'buzz');
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function nonPositiveIntegers(): iterable
    {
        yield 'int1 zero' => [0, 5, 15, 'int1 must be greater than or equal to 1, got 0.'];
        yield 'int1 negative' => [-3, 5, 15, 'int1 must be greater than or equal to 1, got -3.'];
        yield 'int2 zero' => [3, 0, 15, 'int2 must be greater than or equal to 1, got 0.'];
        yield 'int2 negative' => [3, -5, 15, 'int2 must be greater than or equal to 1, got -5.'];
        yield 'limit zero' => [3, 5, 0, 'limit must be greater than or equal to 1, got 0.'];
        yield 'limit negative' => [3, 5, -1, 'limit must be greater than or equal to 1, got -1.'];
        yield 'limit minimum integer' => [3, 5, \PHP_INT_MIN, \sprintf('limit must be greater than or equal to 1, got %d.', \PHP_INT_MIN)];
    }
}
