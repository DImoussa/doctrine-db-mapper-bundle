<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration definition for DbMapperBundle.
 *
 * This class defines the configuration options available for the bundle,
 * allowing users to customize entity and repository namespaces.
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('db_mapper');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('entity_namespace')
                    ->info('Namespace for generated entity classes')
                    ->defaultValue('App\\Entity')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(fn ($v) => !preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $v))
                        ->thenInvalid('Invalid namespace format: %s')
                    ->end()
                ->end()
                ->scalarNode('repository_namespace')
                    ->info('Namespace for generated repository classes')
                    ->defaultValue('App\\Repository')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(fn ($v) => !preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $v))
                        ->thenInvalid('Invalid namespace format: %s')
                    ->end()
                ->end()
                ->booleanNode('skip_existing')
                    ->info('Skip generation if entity file already exists')
                    ->defaultTrue()
                ->end()
                ->booleanNode('detect_many_to_many')
                    ->info('Automatically detect and generate ManyToMany relationships')
                    ->defaultTrue()
                ->end()
                ->booleanNode('generate_bidirectional')
                    ->info('Generate bidirectional relationships (OneToMany/ManyToOne)')
                    ->defaultTrue()
                ->end()
            ->end();

        return $treeBuilder;
    }
}

