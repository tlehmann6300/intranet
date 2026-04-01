<?php

/**
 * PHP-CS-Fixer configuration
 *
 * Enforces PSR-12 coding style across src/, includes/, and tests/.
 * Run: composer lint        (dry-run, shows diff)
 * Run: composer lint:fix    (applies fixes)
 */

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/includes/models',
        __DIR__ . '/includes/handlers',
        __DIR__ . '/includes/services',
        __DIR__ . '/includes/utils',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        '@PHP83Migration'              => true,

        // Imports
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'            => true,

        // Arrays
        'array_syntax'                 => ['syntax' => 'short'],
        'trailing_comma_in_multiline'  => ['elements' => ['arrays', 'parameters', 'arguments']],

        // Strings
        'single_quote'                 => true,

        // Type declarations
        'declare_strict_types'         => true,

        // PHPDoc
        'phpdoc_align'                 => ['align' => 'left'],
        'phpdoc_no_empty_return'       => true,
        'phpdoc_scalar'                => true,
        'phpdoc_trim'                  => true,

        // Blank lines
        'no_extra_blank_lines'         => true,
        'blank_line_before_statement'  => [
            'statements' => ['return', 'throw', 'if', 'foreach', 'while', 'for', 'switch'],
        ],

        // Misc
        'concat_space'                 => ['spacing' => 'one'],
        'ternary_operator_spaces'      => true,
        'binary_operator_spaces'       => true,
        'cast_spaces'                  => ['space' => 'single'],
        'no_whitespace_before_comma_in_array' => true,
    ])
    ->setFinder($finder);
