<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\FrameTemplateCatalog;
use Photobooth\Capture\FrameTemplateImporter;
use PHPUnit\Framework\TestCase;

final class FrameTemplateImporterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/frame-importer-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testImportsTransparentPngAndInfersPhotoSlots(): void
    {
        $source = $this->directory . '/Khung lễ tốt nghiệp.png';
        $this->writeFrame($source, true);

        $template = (new FrameTemplateImporter($this->directory, 5000000, 2000000))
            ->importPng($source, 'Khung Tốt Nghiệp', basename($source));

        self::assertSame('khung-tot-nghiep', $template['id']);
        self::assertSame('Khung Tốt Nghiệp', $template['name']);
        self::assertSame(900, $template['canvas']['width']);
        self::assertSame(1800, $template['canvas']['height']);
        self::assertCount(3, $template['photo_slots']);
        self::assertSame(['slot-1', 'slot-2', 'slot-3'], array_column($template['photo_slots'], 'id'));
        // Every slot fully covers its transparent hole (150..749 x 150..449) with a small bleed.
        foreach ([150, 650, 1150] as $index => $holeTop) {
            $slot = $template['photo_slots'][$index];
            self::assertLessThanOrEqual(150, $slot['x']);
            self::assertLessThanOrEqual($holeTop, $slot['y']);
            self::assertGreaterThanOrEqual(750, $slot['x'] + $slot['width']);
            self::assertGreaterThanOrEqual($holeTop + 300, $slot['y'] + $slot['height']);
            self::assertGreaterThanOrEqual(140, $slot['x']);
            self::assertLessThanOrEqual(620, $slot['width']);
        }
        self::assertFileExists($this->directory . '/khung-tot-nghiep/overlay.png');
        self::assertFileExists($this->directory . '/khung-tot-nghiep/thumbnail.jpg');
        self::assertFileExists($this->directory . '/khung-tot-nghiep/template.json');
        self::assertCount(1, (new FrameTemplateCatalog($this->directory, 5000000))->all());
    }

    public function testUsesFileNameAndAllocatesAUniqueId(): void
    {
        $source = $this->directory . '/Mẫu 01.png';
        $this->writeFrame($source, true);
        $importer = new FrameTemplateImporter($this->directory, 5000000, 2000000);

        $first = $importer->importPng($source, '', basename($source));
        $second = $importer->importPng($source, '', basename($source));

        self::assertSame('mau-01', $first['id']);
        self::assertSame('mau-01-2', $second['id']);
        self::assertSame('Mẫu 01', $first['name']);
    }

    public function testRejectsPngWithoutAnEnclosedTransparentPhotoSlot(): void
    {
        $source = $this->directory . '/opaque.png';
        $this->writeFrame($source, false);

        $this->expectExceptionMessage('NO_PHOTO_SLOTS');
        (new FrameTemplateImporter($this->directory, 5000000, 2000000))
            ->importPng($source, 'Opaque frame', basename($source));
    }

    private function writeFrame(string $path, bool $transparentSlots): void
    {
        $image = imagecreatetruecolor(900, 1800);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $navy = imagecolorallocatealpha($image, 24, 54, 110, 0);
        self::assertIsInt($navy);
        imagefill($image, 0, 0, $navy);
        if ($transparentSlots) {
            $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
            self::assertIsInt($transparent);
            imagefilledrectangle($image, 150, 150, 749, 449, $transparent);
            imagefilledrectangle($image, 150, 650, 749, 949, $transparent);
            imagefilledrectangle($image, 150, 1150, 749, 1449, $transparent);
        }
        imagepng($image, $path);
        imagedestroy($image);
    }
}
