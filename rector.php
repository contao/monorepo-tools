<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php73\Rector\FuncCall\JsonThrowOnErrorRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;

return RectorConfig::configure()
    ->withSets([__DIR__.'/vendor/contao/code-quality/config/rector.php'])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        JsonThrowOnErrorRector::class,
        NullToStrictStringFuncCallArgRector::class,
    ])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/rector_monorepo_cache')
;
