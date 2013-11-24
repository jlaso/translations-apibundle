<?php

namespace JLaso\TranslationsApiBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('translations_api');

        $rootNode
            ->children()
                ->scalarNode('default_locale')
                    ->isRequired()
                ->end()
                ->arrayNode('managed_locales')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->prototype('scalar')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

}
