<?php

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Capture\CameraException;
use Photobooth\Capture\WindowsAgentClient;

header('Content-Type: application/json');
header('Cache-Control: no-store');
session_write_close();
try {
    if (!$config['windows_agent']['enabled'] || !is_string($_GET['capture_id'] ?? null)) {
        throw new CameraException('AGENT_NOT_CONFIGURED');
    }
    $progress = (new WindowsAgentClient($config['windows_agent']))->progress($_GET['capture_id']);
    echo json_encode(['success' => true, 'state' => $progress['state'] ?? 'capturing']);
} catch (CameraException $e) {
    echo json_encode($e->response());
}
