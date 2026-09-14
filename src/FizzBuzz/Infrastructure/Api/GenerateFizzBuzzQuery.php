<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Api;

use App\FizzBuzz\Domain\FizzBuzzParameters;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * HTTP query mapping for GET|HEAD /v1/fizzbuzz (docs/conception.md §5.7).
 */
final readonly class GenerateFizzBuzzQuery
{
    private const REQUIRED = 'This parameter is required and must not be empty.';

    public function __construct(
        #[Assert\NotNull(message: self::REQUIRED)]
        #[Assert\Range(min: 1, max: 2_147_483_647)]
        public ?int $int1 = null,

        #[Assert\NotNull(message: self::REQUIRED)]
        #[Assert\Range(min: 1, max: 2_147_483_647)]
        public ?int $int2 = null,

        #[Assert\NotNull(message: self::REQUIRED)]
        #[Assert\Range(min: 1, max: 10_000)]
        public ?int $limit = null,

        #[Assert\NotBlank(message: self::REQUIRED)]
        #[Assert\Sequentially([
            new Assert\Length(max: 50),
            new Assert\Regex('/^\P{Cc}+\z/u'),
        ])]
        public ?string $str1 = null,

        #[Assert\NotBlank(message: self::REQUIRED)]
        #[Assert\Sequentially([
            new Assert\Length(max: 50),
            new Assert\Regex('/^\P{Cc}+\z/u'),
        ])]
        public ?string $str2 = null,
    ) {
    }

    public function toParameters(): FizzBuzzParameters
    {
        \assert(\is_int($this->int1) && \is_int($this->int2) && \is_int($this->limit) && \is_string($this->str1) && \is_string($this->str2));

        return new FizzBuzzParameters($this->int1, $this->int2, $this->limit, $this->str1, $this->str2);
    }
}
