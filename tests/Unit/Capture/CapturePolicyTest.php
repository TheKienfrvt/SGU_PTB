<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\CameraException;
use Photobooth\Capture\CapturePolicy;
use PHPUnit\Framework\TestCase;

final class CapturePolicyTest extends TestCase
{
    public function testSguProductionRequiresConfiguredUsbEvenWithLegacyCommand(): void
    {
        $config = ['sgu' => ['enabled' => true, 'capture_mode' => 'native'], 'commands' => ['take_picture' => 'anything'],
            'windows_agent' => ['enabled' => false]];
        self::assertTrue(CapturePolicy::requiresUsb($config));
        $this->expectExceptionMessage(CameraException::MESSAGES['AGENT_NOT_CONFIGURED']);
        CapturePolicy::assertAllowed($config, ['style' => 'photo']);
    }

    public function testLegacyAndExplicitDemoRemainAvailable(): void
    {
        foreach ([[], ['sgu' => ['enabled' => false]], ['sgu' => ['enabled' => true, 'capture_mode' => 'browser']],
            ['sgu' => ['enabled' => true, 'capture_mode' => 'native', 'require_usb_capture' => false]],
            ['sgu' => ['enabled' => true], 'dev' => ['demo_images' => true]]] as $config) {
            self::assertFalse(CapturePolicy::requiresUsb($config));
            CapturePolicy::assertAllowed($config, ['style' => 'photo']);
        }
    }

    public function testUsbRejectsBothCanvasEndpointsAndVideo(): void
    {
        $config = ['sgu' => ['enabled' => true, 'capture_mode' => 'native'], 'windows_agent' => ['enabled' => true]];
        CapturePolicy::assertAllowed($config, ['style' => 'photo']);
        foreach ([['canvas' => 'frame'], ['canvasimg' => 'frame'], ['style' => 'video']] as $request) {
            try {
                CapturePolicy::assertAllowed($config, $request);
                self::fail('Preview pixels must not be accepted');
            } catch (CameraException $e) {
                self::assertSame('UNSUPPORTED_CAPTURE_MODE', $e->errorCode);
            }
        }
    }
}
