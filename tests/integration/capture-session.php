<?php

// Usage: php tests/integration/capture-session.php ABSOLUTE_DISPOSABLE_QA_COPY
// Requires the QA-only demo config and print-spy.php; refuses the working repository.
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = realpath($argv[1] ?? '');
if ($root === false || $root === realpath(__DIR__ . '/../..') || !is_file($root . '/print-spy.php')) {
    throw new RuntimeException('Supply an isolated QA copy with a print spy.');
}
$configuration = require $root . '/config/my.config.inc.php';
if (empty($configuration['dev']['demo_images']) || !str_contains($configuration['commands']['print'] ?? '', $root . '/print-spy.php')
    && !str_contains($configuration['commands']['print'] ?? '', str_replace('\\', '/', $root) . '/print-spy.php')) {
    throw new RuntimeException('Refusing non-demo or real printer configuration.');
}
$templateDirectory = $root . '/templates/frames/birthday-01';
if (!is_dir($templateDirectory) && !mkdir($templateDirectory, 0700, true) && !is_dir($templateDirectory)) {
    throw new RuntimeException('Could not create QA frame template.');
}
$writeImage = static function (string $path, int $width, int $height, bool $png): void {
    $image = imagecreatetruecolor($width, $height);
    if (!$image instanceof \GdImage) {
        throw new RuntimeException('GD image creation failed.');
    }
    if ($png) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $color = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if (!is_int($color)) {
            throw new RuntimeException('GD color allocation failed.');
        }
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
    } else {
        $color = imagecolorallocate($image, 74, 188, 234);
        if (!is_int($color)) {
            throw new RuntimeException('GD color allocation failed.');
        }
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $path, 90);
    }
    imagedestroy($image);
};
$writeImage($templateDirectory . '/thumbnail.jpg', 120, 180, false);
$writeImage($templateDirectory . '/overlay.png', 1200, 1800, true);
file_put_contents($templateDirectory . '/template.json', json_encode([
    'id' => 'birthday-01', 'name' => 'Birthday 01', 'enabled' => true,
    'canvas' => ['width' => 1200, 'height' => 1800, 'orientation' => 'portrait', 'background' => '#ffffff'],
    'thumbnail' => 'thumbnail.jpg', 'overlay' => 'overlay.png',
    'photo_slots' => [
        ['x' => 50, 'y' => 50, 'width' => 525, 'height' => 825, 'fit' => 'cover', 'position' => 'center', 'rotation' => 0],
        ['x' => 625, 'y' => 50, 'width' => 525, 'height' => 825, 'fit' => 'contain', 'position' => 'center', 'rotation' => 0],
        ['x' => 50, 'y' => 925, 'width' => 525, 'height' => 825, 'fit' => 'cover', 'position' => 'top', 'rotation' => 1],
        ['x' => 625, 'y' => 925, 'width' => 525, 'height' => 825, 'fit' => 'cover', 'position' => 'bottom', 'rotation' => -1],
    ],
], JSON_THROW_ON_ERROR));
$cookie = 'sgu-qa-' . bin2hex(random_bytes(12));
$call = static function (array $request) use ($root, $cookie): string {
    $request += ['cookie' => $cookie];
    $process = proc_open([PHP_BINARY, __DIR__ . '/session-request.php', $root, base64_encode(json_encode($request, JSON_THROW_ON_ERROR))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run request fixture.');
    }
    fclose($pipes[0]);
    $response = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($errors . $response);
    }
    return $response;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
};
$token = json_decode($call(['endpoint' => 'token']), true, 512, JSON_THROW_ON_ERROR)['csrf'];
$badCsrf = json_decode($call(['post' => ['action' => 'start', 'csrf' => 'wrong']]), true, 512, JSON_THROW_ON_ERROR);
$assert(($badCsrf['error'] ?? '') === 'Invalid CSRF token', 'CSRF rejects mutation');
$sessionId = '';
$action = static function (string $action, array $post = []) use ($call, $token, &$sessionId): array {
    return json_decode($call(['post' => $post + ['action' => $action, 'csrf' => $token, 'session_id' => $sessionId]]), true, 512, JSON_THROW_ON_ERROR);
};
$invalidTemplate = $action('start', ['template_id' => '../../config']);
$assert(($invalidTemplate['error'] ?? '') === 'INVALID_TEMPLATE',
    'API rejects a traversal template ID: ' . json_encode($invalidTemplate));
$started = $action('start', ['template_id' => 'birthday-01']);
$sessionId = $started['session']['id'];
$assert(($started['session']['template_id'] ?? null) === 'birthday-01', 'API persists the validated template ID');
$assert(($started['session']['required'] ?? null) === 4 && ($started['session']['target'] ?? null) === 6,
    'session derives four slots and two spare captures from the template');
$assert($action('start')['session']['id'] === $sessionId, 'start is idempotent');
for ($i = 0; $i < 6; $i++) {
    $captured = $action('capture', ['index' => $i]);
    $assert(($captured['success'] ?? false) === true, 'capture ' . ($i + 1));
}
$assert(count($action('capture', ['index' => 0])['session']['shots']) === 6, 'duplicate shot keeps six captures');
$shots = array_column($captured['session']['shots'], 'id');
$thumb = $call(['method' => 'GET', 'endpoint' => 'captureSessionImage.php', 'get' => ['session_id' => $sessionId, 'shot' => $shots[0]]]);
$size = getimagesizefromstring($thumb);
$assert($size !== false && max($size[0], $size[1]) <= 600, 'selection media is a thumbnail');
$foreign = $call(['cookie' => 'sgu-foreign-' . bin2hex(random_bytes(12)), 'method' => 'GET', 'endpoint' => 'captureSessionImage.php', 'get' => ['session_id' => $sessionId, 'shot' => $shots[0]]]);
$assert($foreign === '', 'another cookie cannot read thumbnails');
$bad = $action('compose', ['selected' => [$shots[0], $shots[0], $shots[1], $shots[2]]]);
$assert(($bad['success'] ?? true) === false, 'duplicate selection rejected');
$composed = $action('compose', ['selected' => array_slice($shots, 0, 4)]);
$assert(($composed['session']['state'] ?? '') === 'final-preview', 'compose produces final preview');
$final = $root . '/data/images/' . basename($composed['session']['final']);
$finalSize = getimagesize($final);
$assert($finalSize !== false && [$finalSize[0], $finalSize[1]] === [1200, 1800], 'final JPEG uses template canvas dimensions');
$hash = hash_file('sha256', $final);
$originalFinal = (string) file_get_contents($final);
file_put_contents($final, $originalFinal . "\0");
$changed = $action('confirm');
$assert(($changed['error'] ?? '') === 'FINAL_CHANGED', 'modified final is rejected before publication');
file_put_contents($final, $originalFinal);
$confirmed = $action('confirm');
$assert(($confirmed['session']['state'] ?? '') === 'confirmed', 'confirmation publishes the final JPEG');
$gallery = json_decode((string) file_get_contents($root . '/data/db.txt'), true, 512, JSON_THROW_ON_ERROR);
$assert(in_array(basename($final), $gallery, true) && count(array_intersect($gallery, $shots)) === 0, 'gallery publishes final only');
$before = is_file($root . '/print-spy.jsonl') ? count(file($root . '/print-spy.jsonl') ?: []) : 0;
$printed = $action('print');
$assert(($printed['session']['state'] ?? '') === 'complete', 'fake print submission acknowledged');
$action('print');
$lines = file($root . '/print-spy.jsonl') ?: [];
$assert(count($lines) === $before + 1, 'repeated print sends one command');
$spy = json_decode(trim($lines[count($lines) - 1]), true, 512, JSON_THROW_ON_ERROR);
$assert($spy['sha256'] === $hash && realpath($spy['path']) === realpath($final), 'preview and printer use the same path and bytes');
$action('cancel');
$assert($action('status')['session'] === null, 'complete session resets');
$replacement = $action('start', ['template_id' => 'birthday-01']);
$sessionId = $replacement['session']['id'];
unlink($templateDirectory . '/template.json');
$stale = $action('status');
$assert(($stale['error'] ?? '') === 'INVALID_TEMPLATE' && ($stale['reselect_template'] ?? false) === true,
    'deleted active template requires a safe re-selection');
$assert($action('status')['session'] === null, 'invalid template releases the stale session pointer');
