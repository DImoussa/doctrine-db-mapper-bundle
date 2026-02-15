<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension class for DbMapperBundle.
 *
 * This class loads the bundle's service configuration and processes
 * the configuration tree defined in Configuration.php.
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class DbMapperExtension extends Extension
{
    /**
     * {@inheritdoc}
     *
     * 
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Store configuration as parameters for use in services
        $container->setParameter('db_mapper.entity_namespace', $config['entity_namespace']);
        $container->setParameter('db_mapper.repository_namespace', $config['repository_namespace']);
        $container->setParameter('db_mapper.skip_existing', $config['skip_existing']);
        $container->setParameter('db_mapper.detect_many_to_many', $config['detect_many_to_many']);
        $container->setParameter('db_mapper.generate_bidirectional', $config['generate_bidirectional']);

        $loader = new FileLocator(__DIR__ . '/../Resources/config');
        $yamlLoader = new YamlFileLoader($container, $loader);
        $yamlLoader->load('services.yaml');
    }
}
