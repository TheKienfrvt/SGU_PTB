<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\SessionImagePipeline;
use PHPUnit\Framework\TestCase;

final class SessionImagePipelineTest extends TestCase
{
    private string $directory;
    private array $settings = ['max_bytes' => 1000000, 'max_pixels' => 1000000, 'output_width' => 600,
        'output_height' => 600, 'jpeg_quality' => 90, 'overlay' => ''];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sgu-image-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testExifRotationLeavesOriginalBytesUntouched(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('EXIF extension required for orientation test');
        }
        $image = imagecreatetruecolor(80, 40);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0II" . pack('vVv', 42, 8, 1) . pack('vvVv', 0x112, 3, 1, 6) . "\0\0" . pack('V', 0);
        $jpeg = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
        $original = $this->directory . '/original.jpg';
        $thumb = $this->directory . '/thumb.jpg';
        file_put_contents($original, $jpeg);
        (new SessionImagePipeline($this->settings))->thumbnail($original, $thumb);
        $size = getimagesize($thumb);
        self::assertNotFalse($size);
        self::assertGreaterThan($size[0], $size[1]);
        self::assertSame($jpeg, file_get_contents($original));
    }

    public function testPixelLimitCheckedBeforeDecode(): void
    {
        $image = imagecreatetruecolor(200, 200);
        self::assertInstanceOf(\GdImage::class, $image);
        $path = $this->directory . '/large.jpg';
        imagejpeg($image, $path);
        $settings = $this->settings;
        $settings['max_pixels'] = 10000;
        $this->expectExceptionMessage('INVALID_JPEG');
        (new SessionImagePipeline($settings))->thumbnail($path, $this->directory . '/thumb.jpg');
    }

    public function testWrongOverlayDimensionsRejected(): void
    {
        $frame = imagecreatetruecolor(20, 20);
        self::assertInstanceOf(\GdImage::class, $frame);
        $settings = $this->settings;
        $settings['overlay'] = $this->directory . '/frame.png';
        imagepng($frame, $settings['overlay']);
        $source = $this->directory . '/source.jpg';
        imagejpeg($frame, $source);
        $this->expectExceptionMessage('INVALID_OVERLAY');
        (new SessionImagePipeline($settings))->compose([$source], $this->directory . '/final.jpg');
    }

    public function testTemplateComposerUsesSlotsContainBackgroundRotationAndOverlay(): void
    {
        $settings = $this->settings;
        $settings['output_width'] = 200;
        $settings['output_height'] = 100;
        $sourceBlue = $this->directory . '/blue.jpg';
        $sourceGreen = $this->directory . '/green.jpg';
        $this->solidJpeg($sourceBlue, 40, 80, [0, 0, 255]);
        $this->solidJpeg($sourceGreen, 80, 40, [0, 255, 0]);
        $overlay = $this->directory . '/overlay.png';
        $frame = imagecreatetruecolor(200, 100);
        self::assertInstanceOf(\GdImage::class, $frame);
        imagealphablending($frame, false);
        imagesavealpha($frame, true);
        $transparent = imagecolorallocatealpha($frame, 0, 0, 0, 127);
        $white = imagecolorallocatealpha($frame, 255, 255, 255, 0);
        self::assertIsInt($transparent);
        self::assertIsInt($white);
        imagefill($frame, 0, 0, $transparent);
        imagefilledrectangle($frame, 0, 0, 9, 9, $white);
        imagepng($frame, $overlay);
        imagedestroy($frame);
        $template = [
            'canvas' => ['width' => 200, 'height' => 100, 'background' => '#ff0000'],
            'background' => null,
            'overlay' => $overlay,
            'photo_slots' => [
                ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'fit' => 'cover', 'position' => 'center', 'rotation' => 5.0],
                ['x' => 100, 'y' => 0, 'width' => 100, 'height' => 100, 'fit' => 'contain', 'position' => 'top', 'rotation' => 0.0],
            ],
        ];
        $final = $this->directory . '/template-final.jpg';
        (new SessionImagePipeline($settings))->compose([$sourceBlue, $sourceGreen], $final, $template);

        $size = getimagesize($final);
        self::assertNotFalse($size);
        self::assertSame([200, 100], [$size[0], $size[1]]);
        $image = imagecreatefromjpeg($final);
        self::assertInstanceOf(\GdImage::class, $image);
        $overlayIndex = imagecolorat($image, 3, 3);
        $marginIndex = imagecolorat($image, 150, 90);
        $photoIndex = imagecolorat($image, 150, 10);
        self::assertIsInt($overlayIndex);
        self::assertIsInt($marginIndex);
        self::assertIsInt($photoIndex);
        $overlayPixel = imagecolorsforindex($image, $overlayIndex);
        $containMargin = imagecolorsforindex($image, $marginIndex);
        $containedPhoto = imagecolorsforindex($image, $photoIndex);
        self::assertGreaterThan(210, $overlayPixel['red']);
        self::assertGreaterThan(180, $containMargin['red']);
        self::assertGreaterThan(150, $containedPhoto['green']);
        imagedestroy($image);
    }

    public function testPrintSheetJoinsTwoFramesEdgeToEdgeAtNativeSize(): void
    {
        $first = $this->directory . '/first.jpg';
        $second = $this->directory . '/second.jpg';
        $this->solidJpeg($first, 100, 300, [220, 20, 20]);
        $this->solidJpeg($second, 100, 300, [20, 40, 220]);
        $sheet = $this->directory . '/a5.jpg';

        (new SessionImagePipeline($this->settings))->composePrintSheet([$first, $second], $sheet);

        $size = getimagesize($sheet);
        self::assertNotFalse($size);
        self::assertSame([200, 300], [$size[0], $size[1]]);
        $image = imagecreatefromjpeg($sheet);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame([300, 300], imageresolution($image));
        // Corners and both sides of the seam belong to a frame: no margin, no gap.
        foreach ([[0, 0, 'red'], [97, 150, 'red'], [102, 150, 'blue'], [199, 299, 'blue']] as [$x, $y, $channel]) {
            $index = imagecolorat($image, $x, $y);
            self::assertIsInt($index);
            self::assertGreaterThan(180, imagecolorsforindex($image, $index)[$channel]);
        }
        imagedestroy($image);
    }

    public function testPrintSheetMatchesSecondFrameToFirstFrameHeight(): void
    {
        $first = $this->directory . '/first.jpg';
        $second = $this->directory . '/second.jpg';
        $this->solidJpeg($first, 100, 300, [220, 20, 20]);
        $this->solidJpeg($second, 200, 600, [20, 40, 220]);
        $sheet = $this->directory . '/a5.jpg';

        (new SessionImagePipeline($this->settings))->composePrintSheet([$first, $second], $sheet);

        $size = getimagesize($sheet);
        self::assertNotFalse($size);
        self::assertSame([200, 300], [$size[0], $size[1]]);
    }

    public function testPrintSheetRejectsJoinedSizeAboveMaxPixels(): void
    {
        $settings = $this->settings;
        $settings['max_pixels'] = 50000;
        $first = $this->directory . '/first.jpg';
        $this->solidJpeg($first, 100, 300, [220, 20, 20]);

        $this->expectExceptionMessage('IMAGE_MEMORY_LIMIT');
        (new SessionImagePipeline($settings))->composePrintSheet([$first, $first], $this->directory . '/a5.jpg');
    }

    private function solidJpeg(string $path, int $width, int $height, array $rgb): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        self::assertIsInt($color);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $path, 100);
        imagedestroy($image);
    }
}
