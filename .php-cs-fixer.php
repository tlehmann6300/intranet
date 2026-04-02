<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer configuration
 *
 * Enforces PSR-12 coding standards across the codebase.
 * Run:  vendor/bin/php-cs-fixer fix          (auto-fix)
 *       vendor/bin/php-cs-fixer fix --dry-run (check only)
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/includes/handlers',
        __DIR__ . '/includes/services',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notPath('vendor')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        '@PHP80Migration'              => true,
        '@PHP80Migration:risky'        => true,

        // Imports
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'            => true,
        'global_namespace_import'      => ['import_classes' => true, 'import_functions' => false],

        // Arrays
        'array_syntax'                 => ['syntax' => 'short'],
        'trailing_comma_in_multiline'  => ['elements' => ['arrays', 'arguments', 'parameters']],

        // Strings
        'single_quote'                 => true,

        // Types
        'declare_strict_types'         => true,
        'void_return'                  => true,

        // Blank lines
        'no_extra_blank_lines'         => true,
        'blank_line_before_statement'  => ['statements' => ['return', 'throw', 'if', 'foreach', 'while', 'for']],

        // Comments
        'no_empty_comment'             => true,

        // Spaces / alignment
        'binary_operator_spaces'       => ['default' => 'align_single_space_minimal'],
        'concat_space'                 => ['spacing' => 'one'],
        'not_operator_with_successor_space' => true,
    ])
    ->setFinder($finder);
