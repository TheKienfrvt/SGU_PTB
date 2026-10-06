<?php

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Capture\CameraException;
use Photobooth\Capture\WindowsAgentClient;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');
header('Cache-Control: no-store');
session_write_close();

try {
    if (!$config['windows_agent']['enabled']) {
        echo json_encode((new CameraException('AGENT_NOT_CONFIGURED'))->response()
            + ['provider' => 'legacy', 'connected' => false]);
        exit();
    }
    $logger = LoggerService::getInstance()->getLogger('main');
    $logger->info('[CAMERA] detect started', ['backend' => 'windows_agent']);
    $status = (new WindowsAgentClient($config['windows_agent']))->status();
    $connected = ($status['camera_connected'] ?? $status['connected'] ?? false) === true;
    $ready = ($status['capture_ready'] ?? $connected) === true;
    $logger->info('[CAMERA] detect completed', ['connected' => $connected, 'capture_ready' => $ready]);
    $camera = is_array($status['camera'] ?? null) ? $status['camera'] : [];
    // Explicit allowlist: no controller paths or other agent configuration in frontend JSON.
    echo json_encode(['success' => true, 'agent_online' => true,
        'connected' => $connected, 'camera_connected' => $connected,
        'camera_model' => $connected && is_string($status['camera_model'] ?? null)
            ? $status['camera_model'] : ($connected && is_string($camera['model'] ?? null) ? $camera['model'] : null),
        'camera_busy' => ($status['camera_busy'] ?? false) === true,
        'capture_ready' => $ready, 'last_error' => null,
        'provider' => 'windows_agent', 'camera' => [
            'manufacturer' => is_string($camera['manufacturer'] ?? null) ? $camera['manufacturer'] : '',
            'model' => is_string($camera['model'] ?? null) ? $camera['model'] : '',
        ]]);
} catch (CameraException $e) {
    LoggerService::getInstance()->getLogger('main')->warning('camera status failed', ['error_code' => $e->errorCode]);
    $agentOnline = !in_array($e->errorCode, ['AGENT_UNREACHABLE', 'AGENT_NOT_CONFIGURED'], true);
    echo json_encode($e->response() + [
        'agent_online' => $agentOnline,
        'connected' => false,
        'camera_connected' => false,
        'camera_model' => null,
        'camera_busy' => $e->errorCode === 'CAMERA_BUSY',
        'capture_ready' => false,
        'last_error' => $e->errorCode,
    ]);
}
