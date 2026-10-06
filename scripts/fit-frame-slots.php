<?php

/**
 * Grows each photo slot in templates/frames/<id>/template.json so it fully
 * covers the transparent hole of overlay.png, plus a small bleed under the
 * frame edge. Slots are never shrunk. The previous manifest is kept as
 * template.json.bak-<timestamp>.
 *
 * Run inside the container:
 *   docker compose exec -u application photobooth php scripts/fit-frame-slots.php --dry-run
 *   docker compose exec -u application photobooth php scripts/fit-frame-slots.php
 */

const BLEED = 2;
const FRAMES_DIRECTORY = __DIR__ . '/../templates/frames';

$dryRun = in_array('--dry-run', $argv, true);
ini_set('memory_limit', '1024M');

$alpha = static fn (GdImage $image, int $x, int $y): int => (imagecolorat($image, $x, $y) >> 24) & 0x7f;

// Walks from ($x, $y) in one direction while the overlay is still (partly) transparent.
$edge = static function (GdImage $image, int $x, int $y, int $dx, int $dy) use ($alpha): int {
    $width = imagesx($image);
    $height = imagesy($image);
    while ($x + $dx >= 0 && $x + $dx < $width && $y + $dy >= 0 && $y + $dy < $height
        && $alpha($image, $x + $dx, $y + $dy) > 0) {
        $x += $dx;
        $y += $dy;
    }

    return $dx !== 0 ? $x : $y;
};

$exitCode = 0;
foreach (glob(FRAMES_DIRECTORY . '/*/template.json') ?: [] as $manifestPath) {
    $directory = dirname($manifestPath);
    $name = basename($directory);
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $slotKey = isset($manifest['photo_slots']) ? 'photo_slots' : 'slots';
    if (!is_array($manifest) || !is_array($manifest[$slotKey] ?? null) || !is_string($manifest['overlay'] ?? null)) {
        fwrite(STDERR, "$name: manifest không hợp lệ, bỏ qua\n");
        $exitCode = 1;
        continue;
    }
    $overlay = @imagecreatefrompng($directory . '/' . basename($manifest['overlay']));
    if (!$overlay instanceof GdImage) {
        fwrite(STDERR, "$name: không đọc được overlay, bỏ qua\n");
        $exitCode = 1;
        continue;
    }
    $width = imagesx($overlay);
    $height = imagesy($overlay);
    $changed = false;

    foreach ($manifest[$slotKey] as $index => $slot) {
        $left = (int) $slot['x'];
        $top = (int) $slot['y'];
        $right = $left + (int) $slot['width'];
        $bottom = $top + (int) $slot['height'];
        $holeLeft = $width;
        $holeTop = $height;
        $holeRight = -1;
        $holeBottom = -1;
        // Probe three lines per axis so a rounded corner or a small decoration cannot hide the true edge.
        foreach ([0.25, 0.5, 0.75] as $fraction) {
            $y = min($height - 1, $top + (int) (($bottom - $top) * $fraction));
            $x = min($width - 1, $left + (int) (($right - $left) * $fraction));
            $cx = min($width - 1, intdiv($left + $right, 2));
            $cy = min($height - 1, intdiv($top + $bottom, 2));
            if ($alpha($overlay, $cx, $y) > 0) {
                $holeLeft = min($holeLeft, $edge($overlay, $cx, $y, -1, 0));
                $holeRight = max($holeRight, $edge($overlay, $cx, $y, 1, 0));
            }
            if ($alpha($overlay, $x, $cy) > 0) {
                $holeTop = min($holeTop, $edge($overlay, $x, $cy, 0, -1));
                $holeBottom = max($holeBottom, $edge($overlay, $x, $cy, 0, 1));
            }
        }
        if ($holeRight < 0 || $holeBottom < 0) {
            echo "$name {$slot['id']}: tâm ô không trong suốt, giữ nguyên\n";
            continue;
        }
        $newLeft = max(0, min($left, $holeLeft - BLEED));
        $newTop = max(0, min($top, $holeTop - BLEED));
        $newRight = min($width, max($right, $holeRight + 1 + BLEED));
        $newBottom = min($height, max($bottom, $holeBottom + 1 + BLEED));
        if ([$newLeft, $newTop, $newRight, $newBottom] === [$left, $top, $right, $bottom]) {
            echo "$name {$slot['id']}: đã phủ kín lỗ\n";
            continue;
        }
        printf(
            "%s %s: x=%d y=%d %dx%d -> x=%d y=%d %dx%d\n",
            $name, $slot['id'], $left, $top, $right - $left, $bottom - $top,
            $newLeft, $newTop, $newRight - $newLeft, $newBottom - $newTop
        );
        $manifest[$slotKey][$index] = array_merge($slot, [
            'x' => $newLeft,
            'y' => $newTop,
            'width' => $newRight - $newLeft,
            'height' => $newBottom - $newTop,
        ]);
        $changed = true;
    }
    imagedestroy($overlay);

    if (!$changed || $dryRun) {
        continue;
    }
    $backup = $manifestPath . '.bak-' . date('Ymd-His');
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!copy($manifestPath, $backup)
        || file_put_contents($manifestPath . '.part', $json . PHP_EOL, LOCK_EX) === false
        || !rename($manifestPath . '.part', $manifestPath)) {
        fwrite(STDERR, "$name: ghi template.json thất bại\n");
        $exitCode = 1;
        continue;
    }
    echo "$name: đã cập nhật (backup: " . basename($backup) . ")\n";
}

echo $dryRun ? "DRY RUN — chưa ghi file nào.\n" : "Xong.\n";
exit($exitCode);
