<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Api;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Precomputes GenerateFizzBuzzQuery's serializer and type-info metadata at build time (`cache:warmup`), into the
 * "system" cache pool baked into the image (`var/cache/prod/pools/system`). Without this, #[MapQueryString]
 * denormalization retries the same writes on every request, which fail because that pool is read-only in prod
 * (docs/conception.md §7.6, §9.3). Symfony's own SerializerCacheWarmer only discovers classes carrying serializer
 * attributes (#[Groups], ...); GenerateFizzBuzzQuery only has Validator constraints, so it needs this warmer.
 */
final readonly class GenerateFizzBuzzQueryCacheWarmer implements CacheWarmerInterface
{
    public function __construct(private SerializerInterface&DenormalizerInterface $serializer)
    {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->serializer->denormalize(
            ['int1' => 1, 'int2' => 1, 'limit' => 1, 'str1' => 'a', 'str2' => 'b'],
            GenerateFizzBuzzQuery::class,
        );

        return [];
    }
}
