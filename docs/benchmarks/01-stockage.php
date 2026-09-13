<?php

// Mesures de stockage SQLite (docs/conception.md §6).
// Usage : php -d memory_limit=1G docs/benchmarks/01-stockage.php
// Bases temporaires dans sys_get_temp_dir(), supprimées à la fin (jusqu'à ~1 Go pendant la mesure à 10 M lignes).

declare(strict_types=1);

require __DIR__ . '/lib.php';

const WINDOW = 100_000;

printf('Environnement : %s%s', environment(), PHP_EOL);
printf('Mode : WAL, synchronous=FULL, disque local%s%s', PHP_EOL, PHP_EOL);

// 1. Historique complet (alternative écartée, §15.1) : pire cas, chaque appel crée une combinaison distincte.
foreach ([1_000_000, 10_000_000] as $rows) {
    [$pdo, $file] = openDatabase('history');
    $pdo->exec(SCHEMA[0]);
    $pdo->exec("WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < $rows) "
        . "INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) "
        . "SELECT x % 97 + 1, x % 89 + 1, x % 10000 + 1, printf('fizz%08d', x), 'buzz', abs(random() % 1000) + 1 FROM c");
    $pdo->exec(SCHEMA[1]);
    $pdo->exec(SCHEMA[2]);
    $top = $pdo->prepare('SELECT int1, int2, limit_value, str1, str2, hits FROM fizzbuzz_request_stat ORDER BY hits DESC, id ASC LIMIT 1');
    $readMs = averageMs(function () use ($top): void { $top->execute(); $top->fetch(); $top->closeCursor(); }, 2000);
    $upsert = $pdo->prepare('INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (int1, int2, limit_value, str1, str2) DO UPDATE SET hits = hits + 1');
    $writeMs = averageMs(function (int $i) use ($upsert): void { $upsert->execute([3, 5, 100, 'new' . $i, 'buzz']); }, 2000);
    printf('[1] Historique complet, %d combinaisons (chaînes de 12 et 4 caractères) : lecture du top %.3f ms, écriture %.3f ms, disque %.0f Mo%s',
        $rows, $readMs, $writeMs, databaseSizeMb($pdo, $file), PHP_EOL);
    unset($pdo, $top, $upsert);
    removeDatabase($file);
}

// 2. Fenêtre pleine (N = 100 000) : enregistrement et lecture complète, chaînes courtes.
[$pdo, $file] = openDatabase('window');
createSchema($pdo);
$pdo->exec("WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < " . WINDOW . ") "
    . "INSERT INTO fizzbuzz_request_stat (int1, int2, limit_value, str1, str2, hits) SELECT 3, 5, 100, printf('fizz%08d', x), 'buzz', 1 FROM c");
$pdo->exec('INSERT INTO fizzbuzz_request_log (stat_id) SELECT id FROM fizzbuzz_request_stat ORDER BY id');
$store = new WindowStore($pdo, WINDOW);
$distinctMs = averageMs(function (int $i) use ($store): void { $store->record(3, 5, 100, 'w' . $i, 'buzz'); }, 5000);
$sameMs = averageMs(function () use ($store): void { $store->record(3, 5, 15, 'fizz', 'buzz'); }, 5000);
$readMs = averageMs(function () use ($store): void { $store->read(); }, 2000);
$violations = invariantViolations($pdo, WINDOW);
printf('[2] Fenêtre pleine N=%d (chaînes courtes) : enregistrement distinct %.3f ms, enregistrement répété %.3f ms, lecture complète %.3f ms, disque %.1f Mo, invariants : %s%s',
    WINDOW, $distinctMs, $sameMs, $readMs, databaseSizeMb($pdo, $file), [] === $violations ? 'OK' : implode(', ', $violations), PHP_EOL);
printf('    Plan de la lecture complète : %s%s', queryPlan($pdo, SQL_READ), PHP_EOL);
foreach (['décrément' => SQL_EVICT_DECREMENT, 'suppression du journal' => SQL_EVICT_LOG, 'suppression des combinaisons' => SQL_EVICT_STAT] as $label => $sql) {
    printf('    Plan de l\'éviction (%s) : %s%s', $label, queryPlan($pdo, $sql, str_contains($sql, ':threshold') ? [':threshold' => 1] : []), PHP_EOL);
}

// 3. Réduction de N au démarrage (review R09) : 100 000 -> 10 000, puis lecture immédiate sans génération.
$store->setWindowSize(10_000);
$start = hrtime(true);
$store->applyWindowSize();
$trimMs = (hrtime(true) - $start) / 1e6;
$row = $store->read();
$violations = invariantViolations($pdo, 10_000);
printf('[3] Réduction 100 000 -> 10 000 : %.0f ms ; lecture immédiate : window_count=%d (<= 10 000 : %s), invariants : %s%s',
    $trimMs, (int) $row['window_count'], (int) $row['window_count'] <= 10_000 ? 'oui' : 'NON', [] === $violations ? 'OK' : implode(', ', $violations), PHP_EOL);
unset($store, $pdo);
removeDatabase($file);

// 4. Pire cas disque (review R06) : fenêtre pleine renouvelée 2 fois, combinaisons toutes distinctes, chaînes de 50 emojis.
[$pdo, $file] = openDatabase('worst');
createSchema($pdo);
$emoji50 = str_repeat(mb_chr(0x1F355), 50);
$store = new WindowStore($pdo, WINDOW);
$start = hrtime(true);
for ($i = 1; $i <= 3 * WINDOW; ++$i) {
    $store->record($i, 5, 100, $emoji50, $emoji50);
}
$totalS = (hrtime(true) - $start) / 1e9;
$violations = invariantViolations($pdo, WINDOW);
printf('[4] Pire cas disque : %d enregistrements distincts (fenêtre renouvelée 2 fois), str1 = str2 = 50 emojis : %.0f s, disque %.1f Mo, invariants : %s%s',
    3 * WINDOW, $totalS, databaseSizeMb($pdo, $file), [] === $violations ? 'OK' : implode(', ', $violations), PHP_EOL);

// 5. Lecture (review R03) : ancienne forme (max et min combinés) contre forme retenue (sous-requêtes séparées).
foreach (['combinée (ancienne)' => SQL_READ_COMBINED, 'séparée (retenue)' => SQL_READ] as $label => $sql) {
    $statement = $pdo->prepare($sql);
    $ms = averageMs(function () use ($statement): void { $statement->execute(); $statement->fetch(); $statement->closeCursor(); }, 500);
    printf('[5] Lecture %-20s : %.3f ms | plan : %s%s', $label, $ms, queryPlan($pdo, $sql), PHP_EOL);
}
unset($store, $pdo);
removeDatabase($file);
