<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Capture\CapturePolicy;
use Photobooth\Capture\FrameTemplateCatalog;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');
header('Cache-Control: no-store');

/**
 * This endpoint intentionally only reports local configuration and writable
 * storage. Browser/UVC readiness is verified in the browser because PHP has
 * no access to the CamLink stream. A configured capture command is not proof
 * that a tethered camera is physically connected.
 */
$browserCapture = ($config['sgu']['capture_mode'] ?? 'native') === 'browser' && !$config['dev']['demo_images'];
$captureSource = $config['dev']['demo_images'] ? 'demo' : ($browserCapture ? 'browser'
    : ($config['windows_agent']['enabled'] ? 'windows_agent' : 'command'));
$captureConfigured = $captureSource !== 'command'
    || trim((string) $config['commands']['take_picture']) !== '';
$usbRequired = CapturePolicy::requiresUsb($config);
if ($usbRequired && !$config['windows_agent']['enabled']) {
    $captureConfigured = false;
}

$tempDirectory = FolderEnum::TEMP->absolute();
$imagesDirectory = FolderEnum::IMAGES->absolute();
$storageWritable = is_dir($tempDirectory)
    && is_writable($tempDirectory)
    && is_dir($imagesDirectory)
    && is_writable($imagesDirectory);
if (!$browserCapture && $config['windows_agent']['enabled']) {
    $originalDirectory = FolderEnum::DATA->absolute() . '/original';
    $storageWritable = $storageWritable
        && is_writable(is_dir($originalDirectory) ? $originalDirectory : FolderEnum::DATA->absolute())
        && (disk_free_space($tempDirectory) ?: 0) > $config['windows_agent']['max_bytes'] * 2;
}
if ($config['sgu']['session_enabled']) {
    $templatesAvailable = (new FrameTemplateCatalog(
        PathUtility::getAbsolutePath('templates/frames'),
        (int) $config['sgu']['max_pixels']
    ))->all() !== [];
    $storageWritable = $storageWritable && is_writable((string) session_save_path())
        && is_writable(FolderEnum::THUMBS->absolute())
        && (disk_free_space($tempDirectory) ?: 0) > $config['sgu']['max_bytes'] * 10;
    $captureConfigured = $captureConfigured && $templatesAvailable;
} else {
    $templatesAvailable = null;
}

echo json_encode([
    'captureSource' => $captureSource,
    'usbRequired' => $usbRequired,
    'captureBackendConfigured' => $captureConfigured,
    // A command string cannot prove that the tethered camera is ready.
    'captureBackendReady' => null,
    'storageWritable' => $storageWritable,
    'templatesAvailable' => $templatesAvailable,
    'printerRequired' => $config['sgu']['printer_required'],
    // There is no portable, safe printer probe in the existing project.
    'printerReady' => null,
]);
