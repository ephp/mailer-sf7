<?php

namespace Ephp\MailflowBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class EphpMailflowExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('ephp_mailflow.default_batch_size', $config['default_batch_size']);
        $container->setParameter('ephp_mailflow.default_send_interval', $config['default_send_interval']);
        $container->setParameter('ephp_mailflow.smtp_connection_timeout', $config['smtp_connection_timeout']);
        $container->setParameter('ephp_mailflow.max_retry_count', $config['max_retry_count']);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');
    }
}
