<?php

declare(strict_types=1);

namespace Photobooth\Capture;

use FilesystemIterator;
use Photobooth\Utility\PathUtility;

/**
 * Server-owned catalog for operator frame manifests.
 *
 * Browser input is only ever an ID. Every manifest and render asset is
 * resolved again below the configured frame root before it is used.
 */
final class FrameTemplateCatalog
{
    private const ID_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,63}\z/D';
    private const ASSET_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';
    private const COLOR_PATTERN = '/\A#[0-9a-fA-F]{6}\z/D';
    private const MAX_CANVAS_DIMENSION = 10000;
    private const MAX_SLOTS = 8;
    private const POSITIONS = ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

    public function __construct(private readonly string $directory, private readonly int $maxCanvasPixels = 50000000)
    {
    }

    /**
     * Invalid and disabled manifests are intentionally omitted so one bad
     * operator asset cannot stop the picker from rendering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_dir($this->directory) || is_link($this->directory)) {
            return [];
        }

        $templates = [];
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $id = $entry->getFilename();
            if (!self::isValidId($id)) {
                continue;
            }
            try {
                $templates[] = $this->get($id);
            } catch (\RuntimeException) {
                continue;
            }
        }

        usort($templates, static function (array $left, array $right): int {
            $byName = strcmp((string) $left['name'], (string) $right['name']);

            return $byName !== 0 ? $byName : strcmp((string) $left['id'], (string) $right['id']);
        });

        return $templates;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        [$manifest, $templateDirectory] = $this->read($id);

        return $this->normalize($manifest, $id, $templateDirectory);
    }

    /**
     * Return validated geometry with absolute, server-only render paths.
     * This value must never be serialized to the browser.
     *
     * @return array<string, mixed>
     */
    public function renderDefinition(string $id): array
    {
        $template = $this->get($id);
        $templateDirectory = $this->templateDirectory($id);

        return [
            'id' => $template['id'],
            'canvas' => $template['canvas'],
            'photo_slots' => $template['photo_slots'],
            'overlay' => $this->resolvedAsset($templateDirectory, basename((string) $template['overlay'])),
            'background' => $template['background'] === null
                ? null
                : $this->resolvedAsset($templateDirectory, basename((string) $template['background'])),
        ];
    }

    private static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function read(string $id): array
    {
        $templateDirectory = $this->templateDirectory($id);
        $manifestPath = $templateDirectory . DIRECTORY_SEPARATOR . 'template.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        try {
            $contents = file_get_contents($manifestPath);
            if ($contents === false) {
                throw new \RuntimeException('INVALID_TEMPLATE');
            }
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        if (!is_array($manifest)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        return [$manifest, $templateDirectory];
    }

    private function templateDirectory(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $root = realpath($this->directory);
        $candidate = $this->directory . DIRECTORY_SEPARATOR . $id;
        $templateDirectory = realpath($candidate);
        if ($root === false || $templateDirectory === false || !is_dir($templateDirectory) || is_link($candidate)
            || !$this->isInside($templateDirectory, $root)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        return $templateDirectory;
    }

    private function isInside(string $path, string $root): bool
    {
        $normalizedPath = rtrim(PathUtility::fixFilePath($path), '/');
        $normalizedRoot = rtrim(PathUtility::fixFilePath($root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }

        return $normalizedPath !== $normalizedRoot && str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function normalize(array $manifest, string $id, string $templateDirectory): array
    {
        if (($manifest['id'] ?? null) !== $id || !is_string($manifest['name'] ?? null)
            || (array_key_exists('enabled', $manifest) && !is_bool($manifest['enabled']))) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        if (($manifest['enabled'] ?? true) !== true) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        $name = trim($manifest['name']);
        if ($name === '' || strlen($name) > 120) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $canvas = $this->canvas($manifest['canvas'] ?? null);
        $thumbnail = $this->asset($manifest['thumbnail'] ?? null, $templateDirectory, ['jpg', 'jpeg'], $canvas, false);
        $overlay = $this->asset($manifest['overlay'] ?? null, $templateDirectory, ['png'], $canvas, true, false, true);
        $background = $this->asset($manifest['background'] ?? null, $templateDirectory, ['png'], $canvas, true, true);
        if ($thumbnail === null || $overlay === null) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        $slotInput = array_key_exists('photo_slots', $manifest) ? $manifest['photo_slots'] : ($manifest['slots'] ?? null);
        $slots = $this->slots($slotInput, $canvas);

        $relativeDirectory = 'templates/frames/' . $id . '/';

        return [
            'id' => $id,
            'name' => $name,
            'enabled' => true,
            'canvas' => $canvas,
            'thumbnail' => $relativeDirectory . $thumbnail,
            'overlay' => $relativeDirectory . $overlay,
            'background' => $background === null ? null : $relativeDirectory . $background,
            'photo_slots' => $slots,
            // Compatibility alias for manifests/tests created in phase 1.
            'slots' => $slots,
        ];
    }

    /** @return array{width: int, height: int, orientation: string, background: string} */
    private function canvas(mixed $value): array
    {
        if (!is_array($value) || !is_int($value['width'] ?? null) || !is_int($value['height'] ?? null)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        $width = $value['width'];
        $height = $value['height'];
        $orientation = $value['orientation'] ?? ($width === $height ? 'square' : ($width > $height ? 'landscape' : 'portrait'));
        $background = $value['background'] ?? '#ffffff';
        if (!is_string($orientation) || !is_string($background)
            || $width < 1 || $height < 1 || $width > self::MAX_CANVAS_DIMENSION || $height > self::MAX_CANVAS_DIMENSION
            || $width * $height > $this->maxCanvasPixels || preg_match(self::COLOR_PATTERN, $background) !== 1
            || !in_array($orientation, ['portrait', 'landscape', 'square'], true)
            || ($orientation === 'portrait' && $height <= $width)
            || ($orientation === 'landscape' && $width <= $height)
            || ($orientation === 'square' && $width !== $height)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        return ['width' => $width, 'height' => $height, 'orientation' => $orientation, 'background' => strtolower($background)];
    }

    /**
     * @param array{width: int, height: int, orientation: string, background: string} $canvas
     * @param array<int, string> $extensions
     */
    private function asset(
        mixed $value,
        string $templateDirectory,
        array $extensions,
        array $canvas,
        bool $mustMatchCanvas,
        bool $optional = false,
        bool $requireTransparency = false,
    ): ?string
    {
        if ($optional && ($value === null || $value === '')) {
            return null;
        }
        if (!is_string($value) || preg_match(self::ASSET_PATTERN, $value) !== 1
            || !in_array(strtolower(pathinfo($value, PATHINFO_EXTENSION)), $extensions, true)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $resolved = $this->resolvedAsset($templateDirectory, $value);
        $image = @getimagesize($resolved);
        $expectedMime = $extensions === ['png'] ? 'image/png' : 'image/jpeg';
        if ($image === false || $image['mime'] !== $expectedMime
            || ($mustMatchCanvas && ($image[0] !== $canvas['width'] || $image[1] !== $canvas['height']))
            || ($requireTransparency && !$this->pngSupportsTransparency($resolved))) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        return $value;
    }

    private function resolvedAsset(string $templateDirectory, string $filename): string
    {
        if (preg_match(self::ASSET_PATTERN, $filename) !== 1) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        $candidate = $templateDirectory . DIRECTORY_SEPARATOR . $filename;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || is_link($candidate) || !$this->isInside($resolved, $templateDirectory)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        return $resolved;
    }

    /** A PNG color type with alpha, or a palette PNG with a tRNS chunk. */
    private function pngSupportsTransparency(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            if (fread($handle, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }
            while (!feof($handle)) {
                $lengthBytes = fread($handle, 4);
                $type = fread($handle, 4);
                if ($lengthBytes === false || $type === false || strlen($lengthBytes) !== 4 || strlen($type) !== 4) {
                    return false;
                }
                $unpacked = unpack('Nlength', $lengthBytes);
                $length = (int) ($unpacked['length'] ?? -1);
                if ($length < 0 || $length > 100000000) {
                    return false;
                }
                $data = $length === 0 ? '' : fread($handle, $length);
                $crc = fread($handle, 4);
                if ($data === false || $crc === false || strlen($data) !== $length || strlen($crc) !== 4) {
                    return false;
                }
                if ($type === 'IHDR' && isset($data[9]) && in_array(ord($data[9]), [4, 6], true)) {
                    return true;
                }
                if ($type === 'tRNS') {
                    return true;
                }
                if ($type === 'IEND') {
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /**
     * @param array{width: int, height: int, orientation: string, background: string} $canvas
     * @return array<int, array{id: string, x: int, y: int, width: int, height: int, fit: string, position: string, rotation: float}>
     */
    private function slots(mixed $value, array $canvas): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > self::MAX_SLOTS) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $slots = [];
        foreach ($value as $index => $slot) {
            $slotId = is_array($slot) ? ($slot['id'] ?? 'slot-' . ($index + 1)) : null;
            $fit = is_array($slot) ? ($slot['fit'] ?? 'cover') : null;
            $position = is_array($slot) ? ($slot['position'] ?? 'center') : null;
            $rotation = is_array($slot) ? ($slot['rotation'] ?? 0) : null;
            if (!is_array($slot) || !is_string($slotId) || preg_match(self::ID_PATTERN, $slotId) !== 1
                || !is_int($slot['x'] ?? null) || !is_int($slot['y'] ?? null) || !is_int($slot['width'] ?? null)
                || !is_int($slot['height'] ?? null) || !is_string($fit) || !in_array($fit, ['cover', 'contain'], true)
                || !is_string($position) || !in_array($position, self::POSITIONS, true)
                || !(is_int($rotation) || is_float($rotation)) || !is_finite((float) $rotation)) {
                throw new \RuntimeException('INVALID_TEMPLATE');
            }
            if (isset($slots[$slotId]) || $slot['x'] < 0 || $slot['y'] < 0 || $slot['width'] < 1 || $slot['height'] < 1
                || $slot['x'] + $slot['width'] > $canvas['width'] || $slot['y'] + $slot['height'] > $canvas['height']
                || $rotation < -360 || $rotation > 360) {
                throw new \RuntimeException('INVALID_TEMPLATE');
            }
            $slots[$slotId] = [
                'id' => $slotId,
                'x' => $slot['x'],
                'y' => $slot['y'],
                'width' => $slot['width'],
                'height' => $slot['height'],
                'fit' => $fit,
                'position' => $position,
                'rotation' => (float) $rotation,
            ];
        }

        return array_values($slots);
    }
}
