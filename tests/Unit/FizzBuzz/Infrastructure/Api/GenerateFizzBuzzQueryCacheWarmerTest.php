<?php

declare(strict_types=1);

namespace App\Tests\Unit\FizzBuzz\Infrastructure\Api;

use App\FizzBuzz\Infrastructure\Api\GenerateFizzBuzzQuery;
use App\FizzBuzz\Infrastructure\Api\GenerateFizzBuzzQueryCacheWarmer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * warmUp() must denormalize a sample GenerateFizzBuzzQuery so the serializer/type-info metadata is computed
 * once at build time, into the cache pool baked into the image — not on every request against a read-only
 * filesystem in prod (docs/conception.md §7.6, §9.3).
 */
final class GenerateFizzBuzzQueryCacheWarmerTest extends TestCase
{
    public function testWarmUpDenormalizesASampleQuery(): void
    {
        $serializer = $this->createMock(SerializerAndDenormalizer::class);
        $serializer->expects(self::once())
            ->method('denormalize')
            ->with(
                self::callback(static fn (array $data): bool => ['int1', 'int2', 'limit', 'str1', 'str2'] === array_keys($data)),
                GenerateFizzBuzzQuery::class,
            );

        $warmer = new GenerateFizzBuzzQueryCacheWarmer($serializer);

        self::assertSame([], $warmer->warmUp('/unused'));
    }

    public function testIsOptional(): void
    {
        $serializer = $this->createStub(SerializerAndDenormalizer::class);

        self::assertTrue((new GenerateFizzBuzzQueryCacheWarmer($serializer))->isOptional());
    }
}

/**
 * PHPUnit cannot mock an intersection type directly: a named interface stands in for `SerializerInterface&DenormalizerInterface`.
 */
interface SerializerAndDenormalizer extends SerializerInterface, DenormalizerInterface
{
}
