<?php

declare(strict_types=1);

namespace App\FizzBuzz\Infrastructure\Cli;

use App\FizzBuzz\Infrastructure\Persistence\SqliteRequestStatisticsStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies STATS_WINDOW_SIZE to the stored calls at container startup, after the migrations and before PHP-FPM
 * (docs/conception.md §6.6, §7.6). Any failure propagates: the container stops (D16).
 */
#[AsCommand(name: 'app:statistics:apply-window', description: 'Evicts the stored calls beyond STATS_WINDOW_SIZE')]
final readonly class ApplyStatisticsWindowCommand
{
    public function __construct(private SqliteRequestStatisticsStore $store)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->store->applyWindowSize();
        $io->success(\sprintf('Statistics window applied: %d calls kept at most.', $this->store->windowSize()));

        return Command::SUCCESS;
    }
}
