<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])

    // ── PHP version modernisation ─────────────────────────────────────────────
    ->withPhpSets(php84: true)

    // ── Prepared sets (each owns its level internally — no withXxxLevel() needed)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: true,
        instanceOf: true,
    )

    // ── Granular strict rules (replaces the removed strictBooleans set) ───────
    ->withRules([
        DisallowedEmptyRuleFixerRector::class,
        ExplicitNullableParamTypeRector::class,
    ])

    // ── Import FQCNs ──────────────────────────────────────────────────────────
    ->withImportNames(
        importNames: true,
        importDocBlockNames: true,
        removeUnusedImports: true,
    )

    // ── Skip false positives in Laravel/ReactPHP patterns ─────────────────────
    ->withSkip([
        RenameVariableToMatchMethodCallReturnTypeRector::class => [
            __DIR__.'/src/Providers',
        ],
        RemoveUnusedVariableAssignRector::class => [
            __DIR__.'/src/ReactPHP',
        ],
    ]);
