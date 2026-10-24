<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x4Migration' => true,
        '@PHP8x4Migration:risky' => true,
        '@PHPUnit11x0Migration:risky' => true,
        'final_internal_class' => true,
        'static_lambda' => true,
        'self_static_accessor' => true,
        'void_return' => true,
        'date_time_immutable' => true,
        'use_arrow_functions' => true,
        'php_unit_strict' => true,
        'phpdoc_order' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'final_class' => true,
        'ordered_class_elements' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        // import_functions/constants disattivati: confliggono con native_*_invocation (\\strlen).
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'native_constant_invocation' => ['scope' => 'namespaced', 'strict' => true],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_to_comment' => ['ignored_tags' => ['var', 'phpstan-var', 'psalm-var']],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder($finder)
;
