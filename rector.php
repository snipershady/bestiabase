<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\CodeQuality\Rector\ClassMethod\NoSetupWithParentCallOverrideRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/config',
        __DIR__ . '/migrations',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
    ])
    ->withSkip([
        __DIR__ . '/config/bundles.php',
        __DIR__ . '/config/reference.php',
        __DIR__ . '/src/Kernel.php',
        // In conflitto con php-cs-fixer (php_unit_test_case_static_method_calls: self):
        // le assert di PHPUnit sono statiche e si chiamano con self::.
        PreferPHPUnitThisCallRector::class,
        // In conflitto con PHPStan (checkMissingOverrideMethodAttribute): toglie #[Override]
        // da setUp()/tearDown(), che PHPStan invece richiede.
        NoSetupWithParentCallOverrideRector::class,
    ])
    ->withParallel()
    ->withPHPStanConfigs([__DIR__ . '/phpstan.dist.neon'])
    ->withCache(__DIR__ . '/var/cache/rector')
    ->withPhpSets(php84: true)
    ->withAttributesSets(all: true)
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        codingStyle: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        // naming disattivato: rinomina proprietà promosse e parametri (es. $hit → $blastHit),
        // cambiando i nomi delle colonne Doctrine e rompendo i named arguments.
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true,
        phpunitNarrowAsserts: true,
        phpunitMockToStub: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withImportNames(removeUnusedImports: true)
;
