<?php

declare(strict_types=1);

namespace App\FizzBuzz\Application;

use App\FizzBuzz\Domain\FizzBuzzGenerator;
use App\FizzBuzz\Domain\FizzBuzzParameters;
use Doctrine\DBAL\Connection;

final class GenerateFizzBuzz
{
    public function __construct(
        private readonly FizzBuzzGenerator $generator,
        private readonly Connection $connection,
    ) {
    }

    /** @return list<string> */
    public function execute(FizzBuzzParameters $parameters): array
    {
        $sequence = $this->generator->generate($parameters);
        $this->connection->executeStatement(
            'UPDATE fizzbuzz_request_stat SET hits = hits + 1 WHERE int1 = ? AND int2 = ?',
            [$parameters->int1, $parameters->int2],
        );

        return $sequence;
    }
}
