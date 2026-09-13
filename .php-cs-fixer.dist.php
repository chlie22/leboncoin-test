<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/config',
        __DIR__.'/migrations',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->notPath([
        'bundles.php',
        'reference.php',
    ])
    ->append([__FILE__])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder)
;
