<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

use Contao\CodeQuality\Fixer\TypeHintOrderFixer;
use PhpCsFixer\Fixer\Alias\ModernizeStrposFixer;
use PhpCsFixer\Fixer\Comment\HeaderCommentFixer;
use PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer;
use PhpCsFixer\Fixer\FunctionNotation\NullableTypeDeclarationForDefaultNullValueFixer;
use PhpCsFixer\Fixer\FunctionNotation\UseArrowFunctionsFixer;
use PhpCsFixer\Fixer\Operator\NoUselessConcatOperatorFixer;
use PhpCsFixer\Fixer\Whitespace\MethodChainingIndentationFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return ECSConfig::configure()
    ->withSets([__DIR__.'/vendor/contao/code-quality/config/ecs.php'])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        MethodChainingIndentationFixer::class => [
            'src/Config/MonorepoConfiguration.php',
        ],
        NoUselessConcatOperatorFixer::class => [
            'tests/Git/TreeTest.php',
        ],
        // TODO: enable the following once PHP 8.1 is the minimum requirement
        ModernizeStrposFixer::class,
        NullableTypeDeclarationForDefaultNullValueFixer::class,
        TrailingCommaInMultilineFixer::class,
        TypeHintOrderFixer::class,
        UseArrowFunctionsFixer::class,
    ])
    ->withParallel()
    ->withSpacing(Option::INDENTATION_SPACES, "\n")
    ->withConfiguredRule(HeaderCommentFixer::class, ['header' => "This file is part of Contao.\n\n(c) Leo Feyer\n\n@license LGPL-3.0-or-later"])
    ->withCache(sys_get_temp_dir().'/monorepo_cache')
;
