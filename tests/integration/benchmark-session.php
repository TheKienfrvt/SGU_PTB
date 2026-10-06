<?php

// CLI-only deterministic GD benchmark. Source JPEG is read-only; destination must not exist.
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../../vendor/autoload.php';
$source = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if (!is_file($source) || $destination === '' || file_exists($destination)) {
    throw new RuntimeException('Supply a JPEG source and a NEW output path.');
}
$settings = ['output_width' => 1800, 'output_height' => 1200, 'max_pixels' => 24000000,
    'max_bytes' => 30000000, 'jpeg_quality' => 90, 'overlay' => ''];
$hash = hash_file('sha256', $source);
$start = microtime(true);
(new Photobooth\Capture\SessionImagePipeline($settings))->compose(array_fill(0, 4, $source), $destination);
echo json_encode(['source_bytes' => filesize($source), 'source_size' => getimagesize($source),
    'elapsed_ms' => (int) ((microtime(true) - $start) * 1000), 'php_peak_bytes_not_process_rss' => memory_get_peak_usage(true),
    'final_bytes' => filesize($destination), 'source_unchanged' => $hash === hash_file('sha256', $source)], JSON_PRETTY_PRINT) . PHP_EOL;
