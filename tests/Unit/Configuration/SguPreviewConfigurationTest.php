<?php

namespace Photobooth\Tests\Unit\Configuration;

use Photobooth\Configuration\PhotoboothConfiguration;
use Photobooth\Utility\ArrayUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class SguPreviewConfigurationTest extends TestCase
{
    #[DataProvider('previewModes')]
    public function testOnlyUnconfiguredSguPreviewGetsDeviceDefault(array $input, string $expected): void
    {
        $config = (new Processor())->processConfiguration(new PhotoboothConfiguration(), [$input]);
        self::assertSame($expected, $config['preview']['mode']);
        self::assertFalse($config['preview']['camTakesPic']);
        self::assertSame('Cam Link', $config['sgu']['camera_label']);
        self::assertSame('', $config['sgu']['camera_device_id']);
        self::assertSame('browser', $config['sgu']['capture_mode']);
        self::assertFalse($config['sgu']['require_usb_capture']);
    }

    public static function previewModes(): array
    {
        return [
            'SGU deployment defaults' => [[], 'device_cam'],
            'SGU explicitly enabled' => [['sgu' => ['enabled' => true]], 'device_cam'],
            'SGU disabled preserves upstream' => [['sgu' => ['enabled' => false]], 'none'],
            'explicit none is respected' => [['preview' => ['mode' => 'none']], 'none'],
            'explicit URL is respected' => [['preview' => ['mode' => 'url']], 'url'],
            'other preview options do not suppress default' => [['preview' => ['videoWidth' => 1920]], 'device_cam'],
        ];
    }

    public function testSavingExplicitNoneDoesNotLoseItAsADefaultValue(): void
    {
        $processor = new Processor();
        $schema = new PhotoboothConfiguration();
        $defaults = $processor->processConfiguration($schema, [[]]);
        $disabled = $processor->processConfiguration($schema, [['preview' => ['mode' => 'none']]]);

        // ConfigurationService persists only values different from defaults.
        $saved = ArrayUtility::diffRecursive($disabled, $defaults);
        self::assertSame('none', $saved['preview']['mode']);
        self::assertSame('none', $processor->processConfiguration($schema, [$saved])['preview']['mode']);
    }
}
