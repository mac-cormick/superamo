<?php
namespace CustomersBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class CustomersExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $basePath = $container->getParameter('kernel.root_dir');
        //загружаем конфиг для customers
        $configPath = $container->getParameter('customers.config.dir');
        $loader = new Loader\YamlFileLoader($container, new FileLocator($basePath . $configPath));
        $loader->load('custom_fields_map.yml');
        $loader->load('leads_statuses_map.yml');
        //загружаем конфиг для customersus
        $configPath = $container->getParameter('customersus.config.dir');
        $loader = new Loader\YamlFileLoader($container, new FileLocator($basePath . $configPath));
        $loader->load('custom_fields_map.yml');
    }
}
