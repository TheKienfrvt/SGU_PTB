<?php

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class WindowsAgentConfiguration
{
    public static function getNode(): NodeDefinition
    {
        return (new TreeBuilder('windows_agent'))->getRootNode()->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('url')->defaultValue('http://host.docker.internal:8765')->end()
                ->scalarNode('token')->defaultValue('')->end()
                ->integerNode('timeout')->min(5)->max(120)->defaultValue(30)
                    ->beforeNormalization()->ifTrue(static fn (mixed $value): bool => is_string($value) && ctype_digit($value))
                    ->then(static fn (mixed $value): int => (int) $value)->end()->end()
                ->integerNode('max_bytes')->min(1024)->max(268435456)->defaultValue(104857600)
                    ->beforeNormalization()->ifTrue(static fn (mixed $value): bool => is_string($value) && ctype_digit($value))
                    ->then(static fn (mixed $value): int => (int) $value)->end()->end()
            ->end();
    }
}
