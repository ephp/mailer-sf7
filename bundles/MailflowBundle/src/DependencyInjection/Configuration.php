<?php

namespace Ephp\MailflowBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ephp_mailflow');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('default_batch_size')
                    ->defaultValue(50)
                    ->min(1)
                    ->max(500)
                ->end()
                ->integerNode('default_send_interval')
                    ->defaultValue(30)
                    ->min(0)
                ->end()
                ->integerNode('smtp_connection_timeout')
                    ->defaultValue(10)
                    ->min(1)
                    ->max(60)
                ->end()
                ->integerNode('max_retry_count')
                    ->defaultValue(3)
                    ->min(0)
                    ->max(10)
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
