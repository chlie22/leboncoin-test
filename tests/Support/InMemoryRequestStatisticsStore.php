<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\FizzBuzz\Application\Exception\StatisticsStoreUnavailable;
use App\FizzBuzz\Application\Model\RequestStatistics;
use App\FizzBuzz\Application\Port\RequestStatisticsStore;
use App\FizzBuzz\Domain\FizzBuzzParameters;

/**
 * In-memory adapter of the port for use case tests, with a "down" mode. Runs the same contract as the SQLite adapter.
 */
final class InMemoryRequestStatisticsStore implements RequestStatisticsStore
{
    /** @var array<string, array{id: int, parameters: FizzBuzzParameters, hits: int}> combinations of the window, by strict key */
    private array $combinations = [];

    /** @var list<string> keys of the recorded calls, oldest first */
    private array $log = [];

    private int $nextCombinationId = 1;

    private ?\Throwable $failure = null;

    private int $recordAttempts = 0;

    public function __construct(private readonly int $windowSize)
    {
        if ($windowSize < 1) {
            throw new \InvalidArgumentException(\sprintf('The window size must be greater than or equal to 1, got %d.', $windowSize));
        }
    }

    public function record(FizzBuzzParameters $parameters): void
    {
        ++$this->recordAttempts;
        $this->failWhenDown();

        $key = self::key($parameters);

        if (isset($this->combinations[$key])) {
            ++$this->combinations[$key]['hits'];
        } else {
            $this->combinations[$key] = ['id' => $this->nextCombinationId++, 'parameters' => $parameters, 'hits' => 1];
        }
        $this->log[] = $key;

        foreach (array_splice($this->log, 0, max(0, \count($this->log) - $this->windowSize)) as $evicted) {
            $combination = $this->combinations[$evicted];
            --$combination['hits'];

            if (0 === $combination['hits']) {
                unset($this->combinations[$evicted]);
            } else {
                $this->combinations[$evicted] = $combination;
            }
        }
    }

    public function findMostFrequent(): RequestStatistics
    {
        $this->failWhenDown();

        $winner = null;
        foreach ($this->combinations as $combination) {
            if (null === $winner
                || $combination['hits'] > $winner['hits']
                || ($combination['hits'] === $winner['hits'] && $combination['id'] < $winner['id'])) {
                $winner = $combination;
            }
        }

        return new RequestStatistics($winner['parameters'] ?? null, $winner['hits'] ?? 0, $this->windowSize, \count($this->log));
    }

    /**
     * Both methods throw $failure until repair(): an expected unavailability by default, or any error to check that
     * callers let unexpected ones through.
     */
    public function breakDown(\Throwable $failure = new StatisticsStoreUnavailable(new \RuntimeException('The in-memory statistics store is down.'))): void
    {
        $this->failure = $failure;
    }

    public function repair(): void
    {
        $this->failure = null;
    }

    public function recordAttempts(): int
    {
        return $this->recordAttempts;
    }

    private function failWhenDown(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /**
     * Strict, typed identity: serialize() keeps types and bytes, unlike == on objects ('0' == '00').
     */
    private static function key(FizzBuzzParameters $parameters): string
    {
        return serialize([$parameters->int1, $parameters->int2, $parameters->limit, $parameters->str1, $parameters->str2]);
    }
}
