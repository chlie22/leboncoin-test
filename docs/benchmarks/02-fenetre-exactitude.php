<?php

// Exactitude de la fenêtre glissante (docs/conception.md §3.4, §6.3, §6.4, §6.6).
// Compare, après chaque opération, le résultat SQL à un modèle en mémoire écrit indépendamment.
// Usage : php docs/benchmarks/02-fenetre-exactitude.php

declare(strict_types=1);

require __DIR__ . '/lib.php';

final class WindowModel
{
    /** @var list<string> */
    private array $queue = [];
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, int> ordre d'entrée dans la fenêtre, pour le départage */
    private array $order = [];
    private int $nextOrder = 1;

    public function __construct(private int $windowSize)
    {
    }

    public function setWindowSize(int $windowSize): void
    {
        $this->windowSize = $windowSize;
    }

    public function record(string $key): void
    {
        if (!isset($this->counts[$key])) {
            $this->counts[$key] = 0;
            $this->order[$key] = $this->nextOrder++;
        }
        ++$this->counts[$key];
        $this->queue[] = $key;
        $this->applyWindowSize();
    }

    public function applyWindowSize(): void
    {
        while (count($this->queue) > $this->windowSize) {
            $key = array_shift($this->queue);
            if (0 === --$this->counts[$key]) {
                unset($this->counts[$key], $this->order[$key]);
            }
        }
    }

    /** @return array{0: ?string, 1: int, 2: int} combinaison gagnante, hits, taille retenue */
    public function top(): array
    {
        $winner = null;
        foreach ($this->counts as $key => $hits) {
            if (null === $winner || $hits > $this->counts[$winner] || ($hits === $this->counts[$winner] && $this->order[$key] < $this->order[$winner])) {
                $winner = $key;
            }
        }

        return [$winner, null === $winner ? 0 : $this->counts[$winner], count($this->queue)];
    }
}

mt_srand(42);
[$pdo, $file] = openDatabase('exactness', 'OFF');
createSchema($pdo);
$store = new WindowStore($pdo, 200);
$model = new WindowModel(200);
$strings = ['fizz', 'Fizz', '0', ' ', mb_chr(0x1F355) . mb_chr(0xE9)];
$comparisons = 0;
$mismatches = 0;

$compare = function (string $step) use ($store, $model, &$comparisons, &$mismatches): void {
    [$key, $hits, $count] = $model->top();
    $row = $store->read();
    $sqlKey = null === $row ? null : implode('|', [$row['int1'], $row['int2'], $row['limit_value'], $row['str1'], $row['str2']]);
    $sqlHits = null === $row ? 0 : (int) $row['hits'];
    $sqlCount = null === $row ? 0 : (int) $row['window_count'];
    ++$comparisons;
    if ($key !== $sqlKey || $hits !== $sqlHits || $count !== $sqlCount) {
        ++$mismatches;
        if ($mismatches <= 5) {
            printf('ÉCART (%s) : modèle [%s, %d, %d] / SQL [%s, %d, %d]%s', $step, $key, $hits, $count, $sqlKey, $sqlHits, $sqlCount, PHP_EOL);
        }
    }
};

$phases = [
    ['N = 200', 200, 6000],
    ['réduction à N = 40, puis appels', 40, 3000],
    ['augmentation à N = 500', 500, 4000],
    ['N = 1 (cas limite)', 1, 500],
];
foreach ($phases as [$label, $windowSize, $operations]) {
    $store->setWindowSize($windowSize);
    $model->setWindowSize($windowSize);
    $store->applyWindowSize();
    $model->applyWindowSize();
    $compare($label . ' : lecture immédiate après changement de N');
    for ($i = 0; $i < $operations; ++$i) {
        $int1 = mt_rand(1, 6);
        $int2 = mt_rand(1, 5);
        $str1 = $strings[mt_rand(0, count($strings) - 1)];
        $store->record($int1, $int2, 15, $str1, 'buzz');
        $model->record(implode('|', [$int1, $int2, 15, $str1, 'buzz']));
        $compare($label);
    }
    $violations = invariantViolations($pdo, $windowSize);
    printf('%-40s : %5d opérations, invariants : %s%s', $label, $operations, [] === $violations ? 'OK' : implode(', ', $violations), PHP_EOL);
}

printf('Comparaisons SQL / modèle : %d, écarts : %d%s', $comparisons, $mismatches, PHP_EOL);
unset($store, $pdo);
removeDatabase($file);
exit(0 === $mismatches ? 0 : 1);
