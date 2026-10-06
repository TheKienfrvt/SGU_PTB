<?php

namespace Photobooth\Capture;

/** SGU production must use the verified USB bridge, never a UVC frame or unprobed command. */
final class CapturePolicy
{
    public static function requiresUsb(array $config): bool
    {
        return ($config['sgu']['enabled'] ?? false)
            && ($config['sgu']['capture_mode'] ?? 'native') === 'native'
            && ($config['sgu']['require_usb_capture'] ?? true)
            && !($config['dev']['demo_images'] ?? false);
    }

    public static function assertAllowed(array $config, array $request): void
    {
        if (self::requiresUsb($config) && !($config['windows_agent']['enabled'] ?? false)) {
            throw new CameraException('AGENT_NOT_CONFIGURED');
        }
        if (($config['sgu']['capture_mode'] ?? 'native') === 'native'
            && ($config['windows_agent']['enabled'] ?? false)
            && (isset($request['canvasimg']) || isset($request['canvas']) || ($request['style'] ?? '') === 'video')) {
            throw new CameraException('UNSUPPORTED_CAPTURE_MODE');
        }
    }
}
