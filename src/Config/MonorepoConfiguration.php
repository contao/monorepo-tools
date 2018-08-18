<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Config;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class MonorepoConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('monorepo');

        $rootNode
            ->children()
                ->scalarNode('monorepo_url')->isRequired()->end()
                ->scalarNode('branch_filter')
                    ->isRequired()
                    ->validate()
                        ->ifTrue(function ($value) {
                            return @preg_match($value, '') === false;
                        })
                        ->thenInvalid('Filter must be a valid RegEx %s given.')
                    ->end()
                ->end()
                ->arrayNode('repositories')
                    ->isRequired()
                    ->useAttributeAsKey('folder')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')->isRequired()->end()
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
