<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer — PSR-12 (ADR-017). Dipakai `composer cs-check` (dry-run) dan `composer cs-fix`.
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests', __DIR__ . '/public'])
    ->exclude(['Views'])
    ->name('*.php')
    ->notName('*.tpl.php');

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                           => true,
        'declare_strict_types'             => true,
        'array_syntax'                     => ['syntax' => 'short'],
        'binary_operator_spaces'           => ['default' => 'single_space', 'operators' => ['=>' => 'align_single_space_minimal', '=' => 'align_single_space_minimal']],
        'no_unused_imports'                => true,
        'ordered_imports'                  => ['sort_algorithm' => 'alpha'],
        'single_quote'                     => true,
        'trailing_comma_in_multiline'      => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_whitespace_in_comment' => true,
        'blank_line_before_statement'      => ['statements' => ['return']],
        'not_operator_with_successor_space' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/build/.php-cs-fixer.cache');
