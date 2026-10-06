<?php

// CLI-only HTTP superglobal fixture; never a public test backdoor.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = $argv[1];
$request = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
$_SERVER['PHP_SELF'] = '/api/' . ($request['endpoint'] ?? 'captureSession.php');
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'];
session_id($request['cookie']);
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];
chdir($root . '/api');
if (($request['endpoint'] ?? '') === 'token') {
    require $root . '/lib/boot.php';
    echo json_encode(['csrf' => $_SESSION['csrf']]);
} else {
    require $root . '/api/' . ($request['endpoint'] ?? 'captureSession.php');
}
