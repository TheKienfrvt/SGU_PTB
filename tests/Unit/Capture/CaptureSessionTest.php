<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\CaptureSession;
use Photobooth\Capture\SessionImagePipeline;
use Photobooth\Capture\SessionStore;
use PHPUnit\Framework\TestCase;

final class CaptureSessionTest extends TestCase
{
    private string $directory;
    private SessionStore $store;
    private CaptureSession $service;
    private string $id;
    private array $settings;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sgu-unit-' . bin2hex(random_bytes(8));
        $this->store = new SessionStore($this->directory);
        $this->service = new CaptureSession($this->store, 'owner');
        $this->id = bin2hex(random_bytes(16));
        $this->settings = ['capture_mode' => 'browser', 'capture_target' => 3, 'required_slots' => 2, 'session_hours' => 1,
            'output_width' => 600, 'output_height' => 600, 'jpeg_quality' => 90,
            'max_bytes' => 1000000, 'max_pixels' => 5000000, 'overlay' => ''];
        $this->store->create($this->id, 'owner', $this->settings);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function jpeg(string $destination): void
    {
        $image = imagecreatetruecolor(80, 60);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, 0x4abcea);
        imagejpeg($image, $destination, 90);
    }

    private function shootAll(): array
    {
        $session = [];
        for ($i = 0; $i < 3; $i++) {
            $session = $this->service->capture($this->id, $i, $this->jpeg(...));
        }
        return $session;
    }

    private function publish(array $sources, string $filename, array $settings): string
    {
        (new SessionImagePipeline($settings))->compose($sources, $this->directory . '/' . $filename);
        return (string) hash_file('sha256', $this->directory . '/' . $filename);
    }

    private function publishSheet(string $filename, string $expectedHash, string $sheetName, array $settings): string
    {
        $source = $this->directory . '/' . $filename;
        self::assertSame($expectedHash, hash_file('sha256', $source));
        $sheet = $this->directory . '/' . $sheetName;
        (new SessionImagePipeline($settings))->composePrintSheet([$source, $source], $sheet);

        return (string) hash_file('sha256', $sheet);
    }

    public function testCaptureRetryDoesNotFireShutterTwiceAndUsesPrivateThumbnail(): void
    {
        $calls = 0;
        $capture = function (string $destination) use (&$calls): void {
            $calls++;
            $reserved = json_decode((string) file_get_contents($this->store->path($this->id)), true);
            self::assertSame('capturing', $reserved['state']);
            $this->jpeg($destination);
        };
        $first = $this->service->capture($this->id, 0, $capture);
        $second = $this->service->capture($this->id, 0, $capture);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);
        self::assertFileExists($this->store->path($this->id, '.' . $first['shots'][0] . '.thumb.jpg'));
    }

    public function testSelectionComposePrintAndLostResponseAreIdempotent(): void
    {
        $session = $this->shootAll();
        $selection = [$session['shots'][2], $session['shots'][0]];
        $final = $this->service->compose($this->id, $selection, $this->publish(...));
        self::assertSame('final-preview', $final['state']);
        self::assertSame($selection, $final['selected']);
        $size = getimagesize($this->directory . '/' . $final['final']);
        self::assertNotFalse($size);
        self::assertSame([600, 600], [$size[0], $size[1]]);
        $hash = hash_file('sha256', $this->directory . '/' . $final['final']);
        $publishCalls = 0;
        $confirmed = $this->service->confirm($this->id, function (string $filename, string $expectedHash) use (&$publishCalls, $hash): void {
            $publishCalls++;
            self::assertSame($hash, $expectedHash);
            self::assertFileExists($this->directory . '/' . $filename);
        });
        $this->service->confirm($this->id, static fn () => self::fail('Duplicate publish'));
        self::assertSame('confirmed', $confirmed['state']);
        self::assertTrue($confirmed['confirmed']);
        self::assertSame(1, $publishCalls);
        $sheet = $this->service->composePrintSheet($this->id, $this->publishSheet(...));
        self::assertSame('sheet-preview', $sheet['state']);
        self::assertSame('A5-pair', $sheet['sheet_format']);
        self::assertSame([1200, 600], array_slice((array) getimagesize($this->directory . '/' . $sheet['sheet']), 0, 2));
        $calls = 0;
        $sheetHash = hash_file('sha256', $this->directory . '/' . $sheet['sheet']);
        $submit = function (string $filename) use (&$calls, $sheetHash): void {
            $calls++;
            self::assertSame($sheetHash, hash_file('sha256', $this->directory . '/' . $filename));
        };
        $printed = $this->service->print($this->id, static fn () => true, $submit);
        $this->service->print($this->id, static fn () => true, $submit);
        self::assertSame('complete', $printed['state']);
        self::assertSame(1, $calls);
        // Unselected original is preserved and not part of final sources.
        self::assertFileExists($this->store->path($this->id, '.' . $session['shots'][1] . '.jpg'));
    }

    public function testInterruptedPrintIsNotRetried(): void
    {
        $session = $this->shootAll();
        $this->service->compose($this->id, array_slice($session['shots'], 0, 2), $this->publish(...));
        $this->service->confirm($this->id, static fn () => true);
        $this->service->composePrintSheet($this->id, $this->publishSheet(...));
        try {
            $this->service->print($this->id, static fn () => true, static function (): void {
                throw new \RuntimeException('COMMAND_UNCERTAIN');
            });
            self::fail('Expected command failure');
        } catch (\RuntimeException $e) {
            self::assertSame('COMMAND_UNCERTAIN', $e->getMessage());
        }
        self::assertSame('print-uncertain', $this->service->status($this->id)['state']);
        $this->expectExceptionMessage('PRINT_UNCERTAIN');
        $this->service->print($this->id, static fn () => true, static fn () => self::fail('Duplicate print'));
    }

    public function testConfirmedSingleFrameCannotPrintBeforeA5SheetExists(): void
    {
        $session = $this->shootAll();
        $this->service->compose($this->id, array_slice($session['shots'], 0, 2), $this->publish(...));
        $this->service->confirm($this->id, static fn () => true);

        $this->expectExceptionMessage('PRINT_UNCERTAIN');
        $this->service->print(
            $this->id,
            static fn () => self::fail('Single frame must not be validated for print'),
            static fn () => self::fail('Single frame must not be submitted to print')
        );
    }

    public function testLegacyPrintSheetIsRegeneratedAsJoinedA5BeforePrinting(): void
    {
        $session = $this->shootAll();
        $this->service->compose($this->id, array_slice($session['shots'], 0, 2), $this->publish(...));
        $this->service->confirm($this->id, static fn () => true);
        $legacy = $this->service->composePrintSheet($this->id, $this->publishSheet(...));
        $legacy['sheet_format'] = 'A6';
        $this->store->locked($this->id, 'owner', fn () => $this->store->save($legacy));

        $publishCalls = 0;
        $regenerated = $this->service->composePrintSheet(
            $this->id,
            function (string $filename, string $expectedHash, string $sheetName, array $settings) use (&$publishCalls): string {
                $publishCalls++;
                return $this->publishSheet($filename, $expectedHash, $sheetName, $settings);
            }
        );

        self::assertSame(1, $publishCalls);
        self::assertSame('A5-pair', $regenerated['sheet_format']);
        self::assertStringStartsWith('sgu_a5_', $regenerated['sheet']);
    }

    public function testBrowserRetakeReplacesOnlyTheSelectedSlot(): void
    {
        $captured = $this->shootAll();
        $selected = array_slice($captured['shots'], 0, 2);
        $this->service->compose($this->id, $selected, $this->publish(...));
        $oldSecond = $selected[1];

        $replaced = $this->service->replace($this->id, 1, $this->jpeg(...));

        self::assertSame('selecting', $replaced['state']);
        self::assertSame($selected[0], $replaced['shots'][0]);
        self::assertSame($selected[0], $replaced['selected'][0]);
        self::assertNotSame($oldSecond, $replaced['shots'][1]);
        self::assertSame($replaced['shots'][1], $replaced['selected'][1]);
        self::assertNull($replaced['final']);
        self::assertFileDoesNotExist($this->store->path($this->id, '.' . $oldSecond . '.jpg'));
        self::assertFileExists($this->store->path($this->id, '.' . $replaced['shots'][1] . '.jpg'));
    }

    public function testFailedCaptureCannotBeRepeated(): void
    {
        try {
            $this->service->capture($this->id, 0, static function (): void {
                throw new \RuntimeException('Camera disconnected');
            });
        } catch (\RuntimeException) {
            self::assertSame('capture-uncertain', $this->service->status($this->id)['state']);
        }
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $this->service->capture($this->id, 0, $this->jpeg(...));
    }

    public function testWrongOwnerAndTraversalAreRejected(): void
    {
        try {
            (new CaptureSession($this->store, 'another kiosk'))->status($this->id);
            self::fail('Wrong owner accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('INVALID_SESSION', $e->getMessage());
        }
        $this->expectExceptionMessage('INVALID_SESSION');
        $this->store->path('../../config');
    }

    public function testTemplateIdIsOptionalForLegacySessionsAndPersistedForNewSessions(): void
    {
        $withTemplateId = bin2hex(random_bytes(16));
        $withTemplate = $this->store->create($withTemplateId, 'owner', $this->settings, 'birthday-01');
        self::assertSame('birthday-01', $withTemplate['template_id']);
        self::assertSame('birthday-01', $this->service->status($withTemplateId)['template_id']);

        $legacyId = bin2hex(random_bytes(16));
        $legacy = $this->store->create($legacyId, 'owner', $this->settings);
        self::assertArrayNotHasKey('template_id', $legacy);
        self::assertSame('preview', $this->service->status($legacyId)['state']);
    }

    public function testDuplicateAndForeignSelectionsRejected(): void
    {
        $session = $this->shootAll();
        foreach ([[$session['shots'][0], $session['shots'][0]], [$session['shots'][0], str_repeat('a', 32)], []] as $selection) {
            try {
                $this->service->compose($this->id, $selection, $this->publish(...));
                self::fail('Invalid selection accepted');
            } catch (\RuntimeException $e) {
                self::assertSame('INVALID_SELECTION', $e->getMessage());
            }
        }
    }

    public function testCleanupOnlyDeletesExpiredOwnedFiles(): void
    {
        $session = $this->shootAll();
        $session['expires_at'] = time() - 1;
        $this->store->locked($this->id, 'owner', fn () => $this->store->save($session));
        file_put_contents($this->directory . '/keep.jpg', 'unrelated');
        self::assertSame(1, $this->store->cleanup());
        self::assertFileExists($this->directory . '/keep.jpg');
        self::assertFileDoesNotExist($this->store->path($this->id));
    }

    public function testInvalidMimeAndPixelLimitRejected(): void
    {
        $path = $this->directory . '/invalid.jpg';
        file_put_contents($path, 'not a jpeg');
        $this->expectExceptionMessage('INVALID_JPEG');
        (new SessionImagePipeline($this->settings))->thumbnail($path, $path . '.thumb.jpg');
    }
}
