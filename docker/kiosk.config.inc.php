<?php

return [
    'ui' => [
        'skip_welcome' => true,
    ],
    'preview' => [
        'mode' => 'device_cam',
        'camTakesPic' => false,
        'style' => 'contain',
        'videoWidth' => 1280,
        'videoHeight' => 720,
    ],
    'windows_agent' => [
        'enabled' => false,
        'timeout' => 45,
        'max_bytes' => 104857600,
    ],
    'sgu' => [
        'enabled' => true,
        'capture_mode' => 'browser',
        'require_usb_capture' => false,
        'preflight_enabled' => true,
        'printer_required' => false,
        'session_enabled' => true,
        'max_pixels' => 50000000,
    ],
    'dev' => [
        'demo_images' => false,
        'loglevel' => 0,
    ],
];
