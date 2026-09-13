<?php

declare(strict_types=1);

namespace App\Tests\Unit\FizzBuzz\Domain;

use App\FizzBuzz\Domain\FizzBuzzGenerator;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FizzBuzzGenerator::class)]
#[UsesClass(FizzBuzzParameters::class)]
final class FizzBuzzGeneratorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('sequences')]
    public function testGeneratesTheSequence(FizzBuzzParameters $parameters, array $expected): void
    {
        self::assertSame($expected, (new FizzBuzzGenerator())->generate($parameters));
    }

    /**
     * Every case of docs/conception.md §3.2.
     *
     * @return iterable<string, array{FizzBuzzParameters, list<string>}>
     */
    public static function sequences(): iterable
    {
        yield 'classic 3, 5, 15' => [
            new FizzBuzzParameters(3, 5, 15, 'fizz', 'buzz'),
            ['1', '2', 'fizz', '4', 'buzz', 'fizz', '7', '8', 'fizz', 'buzz', '11', 'fizz', '13', '14', 'fizzbuzz'],
        ];
        yield 'divisors not coprime' => [
            new FizzBuzzParameters(2, 4, 8, 'a', 'b'),
            ['1', 'a', '3', 'ab', '5', 'a', '7', 'ab'],
        ];
        yield 'empty str1 is a match, not a number' => [
            new FizzBuzzParameters(3, 5, 6, '', 'buzz'),
            ['1', '2', '', '4', 'buzz', ''],
        ];
        yield 'int1 equals int2' => [
            new FizzBuzzParameters(3, 3, 6, 'a', 'b'),
            ['1', '2', 'ab', '4', '5', 'ab'],
        ];
        yield 'int1 is 1' => [
            new FizzBuzzParameters(1, 3, 4, 'a', 'b'),
            ['a', 'a', 'ab', 'a'],
        ];
        yield 'divisors above limit' => [
            new FizzBuzzParameters(2_147_483_647, 11, 5, 'a', 'b'),
            ['1', '2', '3', '4', '5'],
        ];
        yield 'limit 1' => [
            new FizzBuzzParameters(3, 5, 1, 'fizz', 'buzz'),
            ['1'],
        ];
        yield 'limit 1 with int1 1' => [
            new FizzBuzzParameters(1, 5, 1, 'fizz', 'buzz'),
            ['fizz'],
        ];
        yield 'str1 "0" is kept, not replaced by the number' => [
            new FizzBuzzParameters(2, 5, 2, '0', 'b'),
            ['1', '0'],
        ];
        yield 'str1 and str2 "0" concatenated' => [
            new FizzBuzzParameters(1, 1, 1, '0', '0'),
            ['00'],
        ];
        yield 'str1 space' => [
            new FizzBuzzParameters(2, 5, 2, ' ', 'b'),
            ['1', ' '],
        ];
        yield 'str1 equals str2' => [
            new FizzBuzzParameters(2, 2, 2, 'ab', 'ab'),
            ['1', 'abab'],
        ];
        yield 'unicode returned as is' => [
            new FizzBuzzParameters(2, 3, 6, '🍕', 'é'),
            ['1', '🍕', 'é', '🍕', '5', '🍕é'],
        ];
    }
}
