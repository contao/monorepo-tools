<?php

declare(strict_types=1);

/*
 * This file is part of the Contao monorepo tools.
 *
 * (c) Martin Auswöger
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class MonorepoConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('monorepo');

        $rootNode
            ->children()
                ->scalarNode('monorepo_url')
                    ->isRequired()
                ->end()
                ->scalarNode('branch_filter')
                    ->isRequired()
                    ->validate()
                        ->ifTrue(
                            static function ($value) {
                                return false === @preg_match($value, '');
                            }
                        )
                        ->thenInvalid('Filter must be a valid RegEx %s given.')
                    ->end()
                ->end()
                ->arrayNode('repositories')
                    ->isRequired()
                    ->useAttributeAsKey('folder')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')
                                ->isRequired()
                            ->end()
                            ->arrayNode('mapping')
                                ->useAttributeAsKey('sourceHash')
                                ->normalizeKeys(false)
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('composer')
                    ->normalizeKeys(false)
                    ->children()
                        ->arrayNode('require')
                            ->useAttributeAsKey('package')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('conflict')
                            ->useAttributeAsKey('package')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('require-dev')
                            ->useAttributeAsKey('package')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
