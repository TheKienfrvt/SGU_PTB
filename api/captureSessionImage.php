<?php

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Capture\CaptureSession;
use Photobooth\Capture\SessionStore;

header('Cache-Control: private, no-store');
try {
    if (!$config['sgu']['enabled'] || !$config['sgu']['session_enabled']) {
        throw new RuntimeException('Disabled');
    }
    $id = $_GET['session_id'] ?? '';
    $shot = $_GET['shot'] ?? '';
    if (!is_string($id) || !is_string($shot)) {
        throw new RuntimeException('Invalid identifier');
    }
    SessionStore::validateId($shot);
    $owner = hash('sha256', (string) session_id());
    session_write_close();
    $store = new SessionStore(session_save_path() . '/capture-sessions');
    $session = (new CaptureSession($store, $owner))->status($id);
    if (!in_array($shot, $session['shots'], true)) {
        throw new RuntimeException('Missing shot');
    }
    $path = $store->path($id, '.' . $shot . '.thumb.jpg');
    if (!is_file($path)) {
        throw new RuntimeException('Missing thumbnail');
    }
    header('Content-Type: image/jpeg');
    readfile($path);
} catch (Throwable) {
    http_response_code(404);
}
