<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\Rector\Set\SetList;
use Rector\Config\RectorConfig;
use Rector\Php73\Rector\FuncCall\JsonThrowOnErrorRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;

return RectorConfig::configure()
    ->withSets([SetList::CONTAO])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/ecs.php',
        __DIR__.'/rector.php',
    ])
    ->withSkip([
        JsonThrowOnErrorRector::class,
        NullToStrictStringFuncCallArgRector::class,
    ])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/monorepo_rector_cache')
;
