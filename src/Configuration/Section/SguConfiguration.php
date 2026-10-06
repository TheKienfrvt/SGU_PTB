<?php

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Presentation and preflight options for the SGU kiosk experience.
 *
 * This is deliberately isolated from the capture and print configuration so
 * an existing installation can turn the branding off without changing its
 * camera, image or gallery workflow.
 */
final class SguConfiguration
{
    public static function getNode(): NodeDefinition
    {
        return (new TreeBuilder('sgu'))->getRootNode()->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->booleanNode('enabled')->defaultValue(true)->end()
                ->enumNode('capture_mode')->values(['browser', 'native'])->defaultValue('browser')->end()
                ->booleanNode('require_usb_capture')->defaultFalse()->end()
                ->booleanNode('preflight_enabled')->defaultValue(true)->end()
                ->scalarNode('camera_label')->defaultValue('Cam Link')->end()
                ->scalarNode('camera_device_id')->defaultValue('')->end()
                ->booleanNode('printer_required')->defaultValue(false)->end()
                ->booleanNode('session_enabled')->defaultValue(false)->end()
                ->integerNode('required_slots')->min(1)->max(8)->defaultValue(4)->end()
                ->integerNode('capture_target')->min(1)->max(8)->defaultValue(6)->end()
                ->integerNode('countdown_seconds')->min(1)->max(15)->defaultValue(3)->end()
                ->integerNode('shot_preview_ms')->min(200)->max(5000)->defaultValue(1000)->end()
                ->integerNode('complete_seconds')->min(3)->max(60)->defaultValue(8)->end()
                ->integerNode('session_hours')->min(1)->max(168)->defaultValue(24)->end()
                ->integerNode('output_width')->min(600)->max(3600)->defaultValue(1800)->end()
                ->integerNode('output_height')->min(600)->max(3600)->defaultValue(1200)->end()
                ->integerNode('jpeg_quality')->min(60)->max(98)->defaultValue(90)->end()
                ->integerNode('max_pixels')->min(1000000)->max(50000000)->defaultValue(24000000)->end()
                ->integerNode('max_bytes')->min(1000000)->max(100000000)->defaultValue(30000000)->end()
                ->integerNode('command_timeout')->min(5)->max(120)->defaultValue(45)->end()
                ->scalarNode('overlay')->defaultValue('')->end()
            ->end();
    }
}
