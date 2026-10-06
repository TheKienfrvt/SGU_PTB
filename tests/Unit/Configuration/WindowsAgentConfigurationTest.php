<?php

namespace Photobooth\Tests\Unit\Configuration;

use Photobooth\Configuration\PhotoboothConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class WindowsAgentConfigurationTest extends TestCase
{
    public function testLegacyDefaultsAndExplicitAgentOptions(): void
    {
        $processor = new Processor();
        $defaults = $processor->processConfiguration(new PhotoboothConfiguration(), [[]]);
        self::assertFalse($defaults['windows_agent']['enabled']);
        self::assertSame('', $defaults['windows_agent']['token']);
        $config = $processor->processConfiguration(new PhotoboothConfiguration(), [[
            'windows_agent' => ['enabled' => true, 'timeout' => '45', 'max_bytes' => 200000000],
            'preview' => ['camTakesPic' => false],
        ]]);
        self::assertTrue($config['windows_agent']['enabled']);
        self::assertSame(45, $config['windows_agent']['timeout']);
        self::assertFalse($config['preview']['camTakesPic']);
    }

    public function testTimeoutIsBounded(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new PhotoboothConfiguration(), [['windows_agent' => ['timeout' => 9999]]]);
    }
}
