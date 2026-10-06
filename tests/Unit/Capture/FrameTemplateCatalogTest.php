<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\FrameTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class FrameTemplateCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/frame-catalog-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/birthday-01', 0700, true);
        $this->writeImage($this->directory . '/birthday-01/thumbnail.jpg', 120, 180, 'jpeg');
        $this->writeImage($this->directory . '/birthday-01/overlay.png', 1200, 1800, 'png');
        file_put_contents($this->directory . '/birthday-01/template.json', json_encode([
            'id' => 'birthday-01',
            'name' => 'Birthday 01',
            'enabled' => true,
            'canvas' => ['width' => 1200, 'height' => 1800, 'orientation' => 'portrait', 'background' => '#fefefe'],
            'thumbnail' => 'thumbnail.jpg',
            'overlay' => 'overlay.png',
            'photo_slots' => [['x' => 100, 'y' => 260, 'width' => 1000, 'height' => 1200,
                'fit' => 'contain', 'position' => 'top', 'rotation' => 2.5]],
        ], JSON_THROW_ON_ERROR));
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

    public function testReadsOnlyAValidManifestFromTheFrameDirectory(): void
    {
        $templates = (new FrameTemplateCatalog($this->directory))->all();

        self::assertCount(1, $templates);
        self::assertSame('birthday-01', $templates[0]['id']);
        self::assertSame('templates/frames/birthday-01/thumbnail.jpg', $templates[0]['thumbnail']);
        self::assertSame(1200, $templates[0]['canvas']['width']);
        self::assertSame('slot-1', $templates[0]['slots'][0]['id']);
        self::assertSame('contain', $templates[0]['photo_slots'][0]['fit']);
        self::assertSame('top', $templates[0]['photo_slots'][0]['position']);
        self::assertSame('#fefefe', $templates[0]['canvas']['background']);

        $render = (new FrameTemplateCatalog($this->directory))->renderDefinition('birthday-01');
        self::assertSame(realpath($this->directory . '/birthday-01/overlay.png'), $render['overlay']);
        self::assertNull($render['background']);
    }

    public function testInvalidAssetsAreSkippedAndCannotEscapeTheTemplateDirectory(): void
    {
        $path = $this->directory . '/birthday-01/template.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['thumbnail'] = '../thumbnail.jpg';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        $catalog = new FrameTemplateCatalog($this->directory);
        self::assertSame([], $catalog->all());
        $this->expectExceptionMessage('INVALID_TEMPLATE');
        $catalog->get('birthday-01');
    }

    public function testMissingTemplatesAndTraversalIdsAreRejected(): void
    {
        $catalog = new FrameTemplateCatalog($this->directory);
        foreach (['missing', '../birthday-01', '..\\birthday-01', '/birthday-01'] as $id) {
            try {
                $catalog->get($id);
                self::fail('Invalid template ID was accepted: ' . $id);
            } catch (\RuntimeException $error) {
                self::assertSame('INVALID_TEMPLATE', $error->getMessage());
            }
        }
    }

    public function testAnAbsentCatalogIsAnEmptyState(): void
    {
        self::assertSame([], (new FrameTemplateCatalog($this->directory . '/absent'))->all());
    }

    public function testDisabledAndOversizedTemplatesAreNotSelectable(): void
    {
        $path = $this->directory . '/birthday-01/template.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['enabled'] = false;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertSame([], (new FrameTemplateCatalog($this->directory))->all());

        $manifest['enabled'] = true;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertSame([], (new FrameTemplateCatalog($this->directory, 1000000))->all());
    }

    public function testLegacySlotsAndInferredOrientationRemainCompatible(): void
    {
        $path = $this->directory . '/birthday-01/template.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['canvas']['orientation'], $manifest['photo_slots'], $manifest['enabled']);
        $manifest['slots'] = [[
            'id' => 'legacy-slot', 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 1800,
            'fit' => 'cover', 'rotation' => 0,
        ]];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        $template = (new FrameTemplateCatalog($this->directory))->get('birthday-01');
        self::assertSame('portrait', $template['canvas']['orientation']);
        self::assertSame('center', $template['photo_slots'][0]['position']);
    }

    private function writeImage(string $path, int $width, int $height, string $format): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        if ($format === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $color = imagecolorallocatealpha($image, 0, 0, 0, 127);
            self::assertIsInt($color);
            imagefill($image, 0, 0, $color);
            imagepng($image, $path);
        } else {
            $color = imagecolorallocate($image, 74, 188, 234);
            self::assertIsInt($color);
            imagefill($image, 0, 0, $color);
            imagejpeg($image, $path, 90);
        }
        imagedestroy($image);
    }
}
