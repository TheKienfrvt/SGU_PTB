<?php

declare(strict_types=1);

namespace Photobooth\Capture;

/**
 * Imports an operator-supplied transparent PNG as a complete frame template.
 *
 * Photo slots are inferred from large, enclosed transparent regions. The
 * original PNG remains untouched; a validated copy, thumbnail and manifest
 * are published together through an atomic directory rename.
 */
final class FrameTemplateImporter
{
    private const MAX_SLOTS = 8;
    private const SAMPLE_MAX_DIMENSION = 600;
    /** Pixels a slot extends under the opaque frame edge so no background shows at the hole border. */
    private const SLOT_BLEED = 2;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxCanvasPixels = 50000000,
        private readonly int $maxBytes = 30000000,
    ) {
    }

    /** @return array<string, mixed> */
    public function importPng(string $source, string $requestedName = '', string $originalName = ''): array
    {
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('INVALID_UPLOAD');
        }
        $bytes = filesize($source);
        if ($bytes === false || $bytes < 1 || $bytes > $this->maxBytes) {
            throw new \RuntimeException('FILE_TOO_LARGE');
        }

        $info = @getimagesize($source);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $mime !== 'image/png') {
            throw new \RuntimeException('INVALID_PNG');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 600 || $height < 600 || $width > 10000 || $height > 10000
            || $width * $height > $this->maxCanvasPixels) {
            throw new \RuntimeException('INVALID_DIMENSIONS');
        }

        $image = @imagecreatefrompng($source);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('INVALID_PNG');
        }
        try {
            if (!imageistruecolor($image) && !imagepalettetotruecolor($image)) {
                throw new \RuntimeException('INVALID_PNG');
            }
            $slots = $this->detectPhotoSlots($image, $width, $height);
            if ($slots === []) {
                throw new \RuntimeException('NO_PHOTO_SLOTS');
            }
            $name = $this->displayName($requestedName, $originalName);
            $root = $this->writableRoot();
            $lock = @fopen(sys_get_temp_dir() . '/photobooth-frame-import-' . hash('sha256', $root) . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new \RuntimeException('WRITE_FAILED');
            }
            try {
                return $this->publish($root, $source, $image, $name, $width, $height, $slots);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function writableRoot(): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('WRITE_FAILED');
        }
        $root = realpath($this->directory);
        if ($root === false || is_link($this->directory) || !is_writable($root)) {
            throw new \RuntimeException('WRITE_FAILED');
        }

        return $root;
    }

    private function displayName(string $requestedName, string $originalName): string
    {
        $name = trim($requestedName);
        if ($name === '') {
            $name = trim((string) pathinfo($originalName, PATHINFO_FILENAME));
        }
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1) {
            throw new \RuntimeException('INVALID_NAME');
        }

        return $name;
    }

    private function uniqueId(string $name, string $root): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower(is_string($ascii) ? $ascii : '');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $base = trim(substr($slug, 0, 48), '-');
        if ($base === '' || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $base) !== 1) {
            $base = 'frame-' . date('Ymd-His');
        }

        $id = $base;
        for ($suffix = 2; file_exists($root . DIRECTORY_SEPARATOR . $id); ++$suffix) {
            $id = substr($base, 0, 55) . '-' . $suffix;
        }

        return $id;
    }

    /**
     * @param array<int, array{id: string, x: int, y: int, width: int, height: int, fit: string, position: string, rotation: int}> $slots
     * @return array<string, mixed>
     */
    private function publish(
        string $root,
        string $source,
        \GdImage $image,
        string $name,
        int $width,
        int $height,
        array $slots,
    ): array {
        $id = $this->uniqueId($name, $root);
        $staging = $root . DIRECTORY_SEPARATOR . '.import-' . bin2hex(random_bytes(12));
        $target = $root . DIRECTORY_SEPARATOR . $id;
        if (!mkdir($staging, 0755) || is_dir($target)) {
            throw new \RuntimeException('WRITE_FAILED');
        }
        if (!chmod($staging, 0755)) {
            rmdir($staging);
            throw new \RuntimeException('WRITE_FAILED');
        }

        try {
            if (!copy($source, $staging . DIRECTORY_SEPARATOR . 'overlay.png')) {
                throw new \RuntimeException('WRITE_FAILED');
            }
            $this->writeThumbnail($image, $width, $height, $staging . DIRECTORY_SEPARATOR . 'thumbnail.jpg');
            $manifest = [
                'id' => $id,
                'name' => $name,
                'enabled' => true,
                'thumbnail' => 'thumbnail.jpg',
                'overlay' => 'overlay.png',
                'canvas' => [
                    'width' => $width,
                    'height' => $height,
                    'background' => '#ffffff',
                ],
                'photo_slots' => $slots,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($staging . DIRECTORY_SEPARATOR . 'template.json.part', $json . PHP_EOL, LOCK_EX) === false
                || !rename($staging . DIRECTORY_SEPARATOR . 'template.json.part', $staging . DIRECTORY_SEPARATOR . 'template.json')
                || !chmod($staging . DIRECTORY_SEPARATOR . 'overlay.png', 0644)
                || !chmod($staging . DIRECTORY_SEPARATOR . 'thumbnail.jpg', 0644)
                || !chmod($staging . DIRECTORY_SEPARATOR . 'template.json', 0644)
                || !rename($staging, $target)) {
                throw new \RuntimeException('WRITE_FAILED');
            }

            try {
                return (new FrameTemplateCatalog($root, $this->maxCanvasPixels))->get($id);
            } catch (\Throwable) {
                $this->removeDirectory($target);
                throw new \RuntimeException('WRITE_FAILED');
            }
        } catch (\Throwable $error) {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            throw $error;
        }
    }

    private function writeThumbnail(\GdImage $source, int $width, int $height, string $destination): void
    {
        $scale = min(1.0, 360 / $width, 640 / $height);
        $thumbnailWidth = max(1, (int) round($width * $scale));
        $thumbnailHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);
        if (!$thumbnail instanceof \GdImage) {
            throw new \RuntimeException('WRITE_FAILED');
        }
        try {
            $background = imagecolorallocate($thumbnail, 255, 255, 255);
            if ($background === false) {
                throw new \RuntimeException('WRITE_FAILED');
            }
            imagefill($thumbnail, 0, 0, $background);
            imagealphablending($thumbnail, true);
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $thumbnailWidth,
                $thumbnailHeight,
                $width,
                $height,
            ) || !imagejpeg($thumbnail, $destination, 88)) {
                throw new \RuntimeException('WRITE_FAILED');
            }
        } finally {
            imagedestroy($thumbnail);
        }
    }

    /**
     * @return array<int, array{id: string, x: int, y: int, width: int, height: int, fit: string, position: string, rotation: int}>
     */
    private function detectPhotoSlots(\GdImage $source, int $width, int $height): array
    {
        $scale = min(1.0, self::SAMPLE_MAX_DIMENSION / max($width, $height));
        $sampleWidth = max(1, (int) round($width * $scale));
        $sampleHeight = max(1, (int) round($height * $scale));
        $sample = imagecreatetruecolor($sampleWidth, $sampleHeight);
        if (!$sample instanceof \GdImage) {
            throw new \RuntimeException('WRITE_FAILED');
        }
        try {
            imagealphablending($sample, false);
            imagesavealpha($sample, true);
            $transparent = imagecolorallocatealpha($sample, 255, 255, 255, 127);
            if ($transparent === false) {
                throw new \RuntimeException('WRITE_FAILED');
            }
            imagefill($sample, 0, 0, $transparent);
            if (!imagecopyresampled($sample, $source, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $width, $height)) {
                throw new \RuntimeException('INVALID_PNG');
            }

            $total = $sampleWidth * $sampleHeight;
            $mask = str_repeat("\0", $total);
            for ($y = 0; $y < $sampleHeight; ++$y) {
                for ($x = 0; $x < $sampleWidth; ++$x) {
                    $rgba = imagecolorat($sample, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7f;
                    if ($alpha >= 118) {
                        $mask[$y * $sampleWidth + $x] = "\1";
                    }
                }
            }

            $seen = str_repeat("\0", $total);
            $components = [];
            $minimumPixels = max(16, (int) floor($total * 0.01));
            for ($start = 0; $start < $total; ++$start) {
                if ($mask[$start] !== "\1" || $seen[$start] === "\1") {
                    continue;
                }
                $queue = new \SplQueue();
                $queue->enqueue($start);
                $seen[$start] = "\1";
                $count = 0;
                $minX = $sampleWidth;
                $minY = $sampleHeight;
                $maxX = 0;
                $maxY = 0;
                while (!$queue->isEmpty()) {
                    $point = (int) $queue->dequeue();
                    $x = $point % $sampleWidth;
                    $y = intdiv($point, $sampleWidth);
                    ++$count;
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                    $neighbors = [];
                    if ($x > 0) {
                        $neighbors[] = $point - 1;
                    }
                    if ($x + 1 < $sampleWidth) {
                        $neighbors[] = $point + 1;
                    }
                    if ($y > 0) {
                        $neighbors[] = $point - $sampleWidth;
                    }
                    if ($y + 1 < $sampleHeight) {
                        $neighbors[] = $point + $sampleWidth;
                    }
                    foreach ($neighbors as $neighbor) {
                        if ($mask[$neighbor] === "\1" && $seen[$neighbor] !== "\1") {
                            $seen[$neighbor] = "\1";
                            $queue->enqueue($neighbor);
                        }
                    }
                }

                $boxWidth = $maxX - $minX + 1;
                $boxHeight = $maxY - $minY + 1;
                $fill = $count / max(1, $boxWidth * $boxHeight);
                if ($count < $minimumPixels || $boxWidth < $sampleWidth * 0.2 || $boxHeight < $sampleHeight * 0.04
                    || $fill < 0.65 || $minX === 0 || $minY === 0
                    || $maxX === $sampleWidth - 1 || $maxY === $sampleHeight - 1) {
                    continue;
                }
                // Downsampling blurs the hole edge, so the detected box is up to one
                // sample pixel short on each side. Grow by that plus a bleed; the
                // overlay is drawn on top and hides whatever lands under the frame.
                $marginX = (int) ceil($width / $sampleWidth) + self::SLOT_BLEED;
                $marginY = (int) ceil($height / $sampleHeight) + self::SLOT_BLEED;
                $components[] = [
                    'pixels' => $count,
                    'x' => max(0, (int) floor($minX * $width / $sampleWidth) - $marginX),
                    'y' => max(0, (int) floor($minY * $height / $sampleHeight) - $marginY),
                    'right' => min($width, (int) ceil(($maxX + 1) * $width / $sampleWidth) + $marginX),
                    'bottom' => min($height, (int) ceil(($maxY + 1) * $height / $sampleHeight) + $marginY),
                ];
            }

            usort($components, static fn (array $left, array $right): int => $right['pixels'] <=> $left['pixels']);
            $components = array_slice($components, 0, self::MAX_SLOTS);
            usort($components, static fn (array $left, array $right): int => ($left['y'] <=> $right['y']) ?: ($left['x'] <=> $right['x']));

            $slots = [];
            foreach ($components as $index => $component) {
                $slots[] = [
                    'id' => 'slot-' . ($index + 1),
                    'x' => $component['x'],
                    'y' => $component['y'],
                    'width' => $component['right'] - $component['x'],
                    'height' => $component['bottom'] - $component['y'],
                    'fit' => 'cover',
                    'position' => 'center',
                    'rotation' => 0,
                ];
            }

            return $slots;
        } finally {
            imagedestroy($sample);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && !$entry->isLink()) {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
