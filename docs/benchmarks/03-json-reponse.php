<?php

// Taille et mémoire de la réponse JSON (docs/conception.md §3.3).
// Périmètre : génération de la liste + json_encode uniquement. Ne mesure PAS la mémoire totale d'une requête Symfony / PHP-FPM.
// Usage : php docs/benchmarks/03-json-reponse.php

declare(strict_types=1);

const SYMFONY_DEFAULT = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT; // JsonResponse::DEFAULT_ENCODING_OPTIONS = 15
const RETAINED = SYMFONY_DEFAULT | JSON_UNESCAPED_UNICODE;

printf('Environnement : PHP %s, %s %s %s%s%s', PHP_VERSION, php_uname('s'), php_uname('r'), php_uname('m'), PHP_EOL, PHP_EOL);

$pizza = mb_chr(0x1F355);
$eacute = mb_chr(0xE9);

foreach (['a' => 'a', 'é' => $eacute, '<' => '<', 'guillemet' => '"', 'pizza' => $pizza] as $label => $char) {
    printf('[1] %-9s UTF-8 %d octet(s) | JSON défaut Symfony %d | JSON retenu %d%s',
        $label, strlen($char), strlen(json_encode($char, SYMFONY_DEFAULT)) - 2, strlen(json_encode($char, RETAINED)) - 2, PHP_EOL);
}

$zalgo = 'a' . str_repeat(mb_chr(0x301), 500);
printf('[2] Texte Zalgo : %d graphème(s), %d points de code, %d octets%s', grapheme_strlen($zalgo), mb_strlen($zalgo), strlen($zalgo), PHP_EOL);

function worstCase(int $limit, int $length, string $char, int $flags): string
{
    $label = str_repeat($char, $length);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);
    $list = [];
    for ($i = 1; $i <= $limit; ++$i) {
        $list[] = $label . $label; // int1 = int2 = 1 : chaque élément vaut str1str2
    }
    $json = json_encode($list, $flags);
    $result = sprintf('limit=%d, %d x %s, options %d : JSON %.2f Mo, gzip %.1f Ko, pic mémoire (génération + encodage) %.1f Mo, %.0f ms',
        $limit, $length, $char, $flags, strlen($json) / 1e6, strlen(gzencode($json, 6)) / 1e3, (memory_get_peak_usage() - $before) / 1e6, (hrtime(true) - $start) / 1e6);
    unset($list, $json);

    return $result;
}

foreach ([
    [10_000, 100, $pizza, SYMFONY_DEFAULT],
    [10_000, 50, $pizza, SYMFONY_DEFAULT],
    [10_000, 50, $pizza, RETAINED],
    [10_000, 50, '"', RETAINED],
    [10_000, 50, 'a', RETAINED],
] as [$limit, $length, $char, $flags]) {
    printf('[3] %s%s', worstCase($limit, $length, $char, $flags), PHP_EOL);
}
