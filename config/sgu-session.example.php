<?php

// Merge these keys into the returned array in config/my.config.inc.php.
// Do not replace an existing camera/USB-agent/printer configuration.
return [
    'sgu' => [
        'enabled' => true,
        'session_enabled' => true,
        // Capture the live Cam Link frame in the browser; no USB shutter command
        // or Windows Camera Agent is required for this mode.
        'capture_mode' => 'browser',
        'require_usb_capture' => false,
        'required_slots' => 4,
        'capture_target' => 4,
        'output_width' => 1800,
        'output_height' => 1200,
        'jpeg_quality' => 90,
        'countdown_seconds' => 3,
        'shot_preview_ms' => 1000,
        'complete_seconds' => 8,
        'session_hours' => 24,
        // Optional exact-size PNG with the desired SGU logo/frame/text/QR already laid out.
        // A server-local path only. No legacy print frame/text/crop is applied after preview.
        'overlay' => '',
    ],
];
