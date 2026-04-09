<?php

/**
 * PHP-CS-Fixer configuration.
 * Currently scoped to the tests/ directory.
 * Extend the paths array to include src/ or includes/ once
 * those files have been brought into compliance with @PSR2.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
