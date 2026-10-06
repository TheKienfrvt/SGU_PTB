<?php

declare(strict_types=1);

namespace Photobooth\Capture;

use Photobooth\Image;

/** One source JPEG at a time; thumbnails never replace camera originals. */
final class SessionImagePipeline
{
    public const PRINT_SHEET_PPI = 300;

    public function __construct(private array $settings)
    {
    }

    private function decode(string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            throw new \RuntimeException('EXIF_UNAVAILABLE');
        }
        $info = @getimagesize($path);
        if (!is_file($path) || filesize($path) > $this->settings['max_bytes'] || $info === false
            || $info[2] !== IMAGETYPE_JPEG || $info[0] * $info[1] > $this->settings['max_pixels']) {
            throw new \RuntimeException('INVALID_JPEG');
        }
        // GD allocations may not be counted by memory_get_usage; reserve conservatively.
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $estimate = ($info[0] * $info[1] * 12) + ($this->settings['output_width'] * $this->settings['output_height'] * 12);
        if ($limit > 0 && $estimate + memory_get_usage(true) > $limit * 0.85) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }
        $image = (new Image())->createFromImage($path);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('INVALID_JPEG');
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $degrees = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };
        if ($degrees !== 0) {
            $rotated = imagerotate($image, $degrees, 0);
            if (!$rotated instanceof \GdImage) {
                throw new \RuntimeException('INVALID_JPEG');
            }
            imagedestroy($image);
            $image = $rotated;
        }

        return $image;
    }

    public function thumbnail(string $source, string $destination): void
    {
        $image = $this->decode($source);
        $thumb = (new Image())->resizeImage($image, 600, 600);
        imagedestroy($image);
        if (!$thumb instanceof \GdImage || !imagejpeg($thumb, $destination, $this->settings['jpeg_quality'])) {
            throw new \RuntimeException('IMAGE_WRITE_FAILED');
        }
        imagedestroy($thumb);
    }

    /**
     * @param array<int, string> $sources
     * @param null|array<string, mixed> $template A catalog renderDefinition(), never browser data.
     */
    public function compose(array $sources, string $destination, ?array $template = null): void
    {
        if ($sources === [] || count($sources) > 8) {
            throw new \RuntimeException('INVALID_SELECTION');
        }
        if ($template === null) {
            $this->composeLegacyGrid($sources, $destination);

            return;
        }
        $this->composeTemplate($sources, $destination, $template);
    }

    /**
     * Build one print asset by joining two already-finished frame JPEGs edge to edge.
     *
     * No margin, gap or scaling: the sheet is exactly the two frames side by side at their
     * native pixel size. Only when the second frame has a different height is it resampled
     * to the first frame's height so the edges still meet.
     *
     * @param array<int, string> $sources Exactly two finished JPEG paths.
     */
    public function composePrintSheet(array $sources, string $destination): void
    {
        if (count($sources) !== 2) {
            throw new \RuntimeException('INVALID_SELECTION');
        }

        $sizes = [];
        foreach (array_values($sources) as $path) {
            $info = @getimagesize($path);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw new \RuntimeException('INVALID_JPEG');
            }
            $sizes[] = [$info[0], $info[1]];
        }
        $height = $sizes[0][1];
        $widths = array_map(
            static fn (array $size): int => $size[1] === $height ? $size[0] : max(1, (int) round($size[0] * $height / $size[1])),
            $sizes
        );
        $width = array_sum($widths);
        if ($width * $height > (int) $this->settings['max_pixels']) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }
        $temporary = $destination . '.part';
        try {
            imageresolution($canvas, self::PRINT_SHEET_PPI, self::PRINT_SHEET_PPI);
            imagefill($canvas, 0, 0, 0xffffff);
            $x = 0;
            foreach (array_values($sources) as $index => $path) {
                $source = $this->decode($path);
                try {
                    $sourceWidth = imagesx($source);
                    $sourceHeight = imagesy($source);
                    if ($sourceWidth === $widths[$index] && $sourceHeight === $height) {
                        imagecopy($canvas, $source, $x, 0, 0, 0, $sourceWidth, $sourceHeight);
                    } else {
                        imagecopyresampled($canvas, $source, $x, 0, 0, 0, $widths[$index], $height, $sourceWidth, $sourceHeight);
                    }
                } finally {
                    imagedestroy($source);
                }
                $x += $widths[$index];
            }
            if (!imagejpeg($canvas, $temporary, (int) $this->settings['jpeg_quality'])
                || !rename($temporary, $destination)) {
                throw new \RuntimeException('IMAGE_WRITE_FAILED');
            }
        } finally {
            imagedestroy($canvas);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<int, string> $sources */
    private function composeTemplate(array $sources, string $destination, array $template): void
    {
        $canvasDefinition = $template['canvas'] ?? null;
        $slots = $template['photo_slots'] ?? null;
        if (!is_array($canvasDefinition) || !is_array($slots) || !array_is_list($slots) || count($sources) !== count($slots)) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }
        $width = (int) ($canvasDefinition['width'] ?? 0);
        $height = (int) ($canvasDefinition['height'] ?? 0);
        if ($width !== (int) $this->settings['output_width'] || $height !== (int) $this->settings['output_height']
            || $width < 1 || $height < 1 || $width * $height > (int) $this->settings['max_pixels']) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }
        $temporary = $destination . '.part';
        try {
            imagealphablending($canvas, true);
            imagefill($canvas, 0, 0, $this->color($canvas, (string) ($canvasDefinition['background'] ?? '#ffffff')));
            $background = $template['background'] ?? null;
            if (is_string($background) && $background !== '') {
                $this->applyPngLayer($canvas, $background, $width, $height, 'INVALID_TEMPLATE');
            }
            foreach (array_values($sources) as $index => $path) {
                $source = $this->decode($path);
                try {
                    $this->placeInSlot($canvas, $source, $slots[$index]);
                } finally {
                    imagedestroy($source);
                }
            }
            $overlay = $template['overlay'] ?? null;
            if (!is_string($overlay) || $overlay === '') {
                throw new \RuntimeException('INVALID_TEMPLATE');
            }
            $this->applyPngLayer($canvas, $overlay, $width, $height, 'INVALID_OVERLAY');
            if (!imagejpeg($canvas, $temporary, $this->settings['jpeg_quality']) || !rename($temporary, $destination)) {
                throw new \RuntimeException('IMAGE_WRITE_FAILED');
            }
        } finally {
            imagedestroy($canvas);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<string, mixed> $slot */
    private function placeInSlot(\GdImage $canvas, \GdImage $source, array $slot): void
    {
        $slotWidth = (int) ($slot['width'] ?? 0);
        $slotHeight = (int) ($slot['height'] ?? 0);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $rotation = (float) ($slot['rotation'] ?? 0.0);
        $radians = deg2rad($rotation);
        $cosine = abs(cos($radians));
        $sine = abs(sin($radians));
        if (($slot['fit'] ?? '') === 'cover') {
            // Inverse-rotated slot bounds ensure no transparent corner remains.
            $scale = max(
                (($cosine * $slotWidth) + ($sine * $slotHeight)) / $sourceWidth,
                (($sine * $slotWidth) + ($cosine * $slotHeight)) / $sourceHeight
            );
        } elseif (($slot['fit'] ?? '') === 'contain') {
            $rotatedUnitWidth = ($cosine * $sourceWidth) + ($sine * $sourceHeight);
            $rotatedUnitHeight = ($sine * $sourceWidth) + ($cosine * $sourceHeight);
            $scale = min($slotWidth / $rotatedUnitWidth, $slotHeight / $rotatedUnitHeight);
        } else {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $scaledWidth = max(1, (int) ceil($sourceWidth * $scale));
        $scaledHeight = max(1, (int) ceil($sourceHeight * $scale));
        $scaled = imagecreatetruecolor($scaledWidth, $scaledHeight);
        if (!$scaled instanceof \GdImage) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }
        try {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            if ($transparent === false) {
                throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
            }
            imagefill($scaled, 0, 0, $transparent);
            imagecopyresampled($scaled, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $sourceWidth, $sourceHeight);
            $placed = $scaled;
            if (abs(fmod($rotation, 360.0)) > 0.0001) {
                $placed = imagerotate($scaled, $rotation, $transparent);
                if (!$placed instanceof \GdImage) {
                    throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
                }
                imagesavealpha($placed, true);
            }
            try {
                [$offsetX, $offsetY] = $this->position(
                    (string) ($slot['position'] ?? 'center'),
                    $slotWidth,
                    $slotHeight,
                    imagesx($placed),
                    imagesy($placed)
                );
                $sourceX = max(0, -$offsetX);
                $sourceY = max(0, -$offsetY);
                $destinationX = max(0, $offsetX);
                $destinationY = max(0, $offsetY);
                $copyWidth = min(imagesx($placed) - $sourceX, $slotWidth - $destinationX);
                $copyHeight = min(imagesy($placed) - $sourceY, $slotHeight - $destinationY);
                if ($copyWidth < 1 || $copyHeight < 1) {
                    throw new \RuntimeException('INVALID_TEMPLATE');
                }
                imagecopy(
                    $canvas,
                    $placed,
                    (int) $slot['x'] + $destinationX,
                    (int) $slot['y'] + $destinationY,
                    $sourceX,
                    $sourceY,
                    $copyWidth,
                    $copyHeight
                );
            } finally {
                if ($placed !== $scaled) {
                    imagedestroy($placed);
                }
            }
        } finally {
            imagedestroy($scaled);
        }
    }

    /** @return array{0: int, 1: int} */
    private function position(string $position, int $containerWidth, int $containerHeight, int $contentWidth, int $contentHeight): array
    {
        $horizontal = str_contains($position, 'left') ? 0
            : (str_contains($position, 'right') ? $containerWidth - $contentWidth : (int) round(($containerWidth - $contentWidth) / 2));
        $vertical = str_contains($position, 'top') ? 0
            : (str_contains($position, 'bottom') ? $containerHeight - $contentHeight : (int) round(($containerHeight - $contentHeight) / 2));

        return [$horizontal, $vertical];
    }

    private function color(\GdImage $image, string $hex): int
    {
        if (preg_match('/\A#[0-9a-fA-F]{6}\z/D', $hex) !== 1) {
            throw new \RuntimeException('INVALID_TEMPLATE');
        }

        $color = imagecolorallocate(
            $image,
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2))
        );
        if ($color === false) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }

        return $color;
    }

    private function applyPngLayer(\GdImage $canvas, string $path, int $width, int $height, string $error): void
    {
        $info = @getimagesize($path);
        if (!is_file($path) || $info === false || $info[2] !== IMAGETYPE_PNG || $info[0] !== $width || $info[1] !== $height
            || filesize($path) > $this->settings['max_bytes']) {
            throw new \RuntimeException($error);
        }
        $layer = @imagecreatefrompng($path);
        if (!$layer instanceof \GdImage) {
            throw new \RuntimeException($error);
        }
        try {
            imagealphablending($canvas, true);
            imagecopy($canvas, $layer, 0, 0, 0, 0, $width, $height);
        } finally {
            imagedestroy($layer);
        }
    }

    /** @param array<int, string> $sources */
    private function composeLegacyGrid(array $sources, string $destination): void
    {
        $width = $this->settings['output_width'];
        $height = $this->settings['output_height'];
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException('IMAGE_MEMORY_LIMIT');
        }
        $temporary = $destination . '.part';
        try {
            imagefill($canvas, 0, 0, 0xffffff);
            $columns = count($sources) === 1 ? 1 : 2;
            $rows = (int) ceil(count($sources) / $columns);
            $gap = (int) round(min($width, $height) * 0.02);
            $slotWidth = (int) floor(($width - ($columns + 1) * $gap) / $columns);
            $slotHeight = (int) floor(($height - ($rows + 1) * $gap) / $rows);
            foreach (array_values($sources) as $index => $path) {
                $source = $this->decode($path);
                $scale = max($slotWidth / imagesx($source), $slotHeight / imagesy($source));
                $cropWidth = (int) round($slotWidth / $scale);
                $cropHeight = (int) round($slotHeight / $scale);
                imagecopyresampled(
                    $canvas,
                    $source,
                    $gap + ($index % $columns) * ($slotWidth + $gap),
                    $gap + intdiv($index, $columns) * ($slotHeight + $gap),
                    (int) ((imagesx($source) - $cropWidth) / 2),
                    (int) ((imagesy($source) - $cropHeight) / 2),
                    $slotWidth,
                    $slotHeight,
                    $cropWidth,
                    $cropHeight
                );
                imagedestroy($source);
            }
            $overlay = (string) ($this->settings['overlay'] ?? '');
            if ($overlay !== '') {
                $this->applyPngLayer($canvas, $overlay, $width, $height, 'INVALID_OVERLAY');
            }
            if (!imagejpeg($canvas, $temporary, $this->settings['jpeg_quality']) || !rename($temporary, $destination)) {
                throw new \RuntimeException('IMAGE_WRITE_FAILED');
            }
        } finally {
            imagedestroy($canvas);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
