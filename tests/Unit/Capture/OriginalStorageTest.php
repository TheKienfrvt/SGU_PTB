<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Capture\CameraException;
use Photobooth\Capture\OriginalStorage;
use PHPUnit\Framework\TestCase;

final class OriginalStorageTest extends TestCase
{
    private string $directory;
    private string $jpeg;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/photobooth-original-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $image = imagecreatetruecolor(32, 24);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $comment = 'test camera metadata retained byte-for-byte';
        $this->jpeg = substr($jpeg, 0, 2) . "\xff\xfe" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                chmod($file, 0600);
                unlink($file);
            }
        }
        if (is_file($this->directory . '/.htaccess')) {
            unlink($this->directory . '/.htaccess');
        }
        rmdir($this->directory);
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    private function metadata(string $bytes): array
    {
        return ['capture_id' => str_repeat('a', 32), 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    public function testOriginalIsUnchangedWhenWorkingCopyIsProcessed(): void
    {
        $storage = new OriginalStorage($this->directory, 1000000);
        $stream = $this->stream($this->jpeg);
        $record = $storage->receive($stream, $this->metadata($this->jpeg), $this->directory . '/working.jpg', 'final.jpg');
        fclose($stream);
        $original = $this->directory . '/' . str_repeat('a', 32) . '.jpg';
        self::assertSame($this->jpeg, file_get_contents($original));
        self::assertSame(hash_file('sha256', $original), hash_file('sha256', $this->directory . '/working.jpg'));
        $copy = imagecreatefromjpeg($this->directory . '/working.jpg');
        self::assertInstanceOf(\GdImage::class, $copy);
        imagefilter($copy, IMG_FILTER_NEGATE);
        imagejpeg($copy, $this->directory . '/working.jpg', 50);
        self::assertSame(hash('sha256', $this->jpeg), hash_file('sha256', $original));
        self::assertNotSame(hash_file('sha256', $original), hash_file('sha256', $this->directory . '/working.jpg'));
        self::assertSame([$record], $storage->forImage('final.jpg'));
        self::assertSame([], $storage->forImage('legacy.jpg'));
    }

    public function testHashMismatchIsRejectedAndPartialOriginalRemoved(): void
    {
        $storage = new OriginalStorage($this->directory, 1000000);
        $stream = $this->stream($this->jpeg);
        $metadata = $this->metadata($this->jpeg);
        $metadata['sha256'] = str_repeat('0', 64);
        try {
            $storage->receive($stream, $metadata, $this->directory . '/working.jpg', 'final.jpg');
            self::fail('Expected integrity failure');
        } catch (CameraException $e) {
            self::assertSame('INTEGRITY_FAILED', $e->errorCode);
            self::assertSame([], glob($this->directory . '/*.jpg'));
        } finally {
            fclose($stream);
        }
    }

    public function testTwentyMegabyteJpegIsStreamedWithoutChangingBytes(): void
    {
        // Valid JPEG comment segments simulate a large camera file without a huge GD bitmap.
        $segment = "\xff\xfe" . pack('n', 65535) . str_repeat('x', 65533);
        $jpeg = substr($this->jpeg, 0, 2) . str_repeat($segment, 321) . substr($this->jpeg, 2);
        self::assertGreaterThan(20 * 1024 * 1024, strlen($jpeg));
        $stream = $this->stream($jpeg);
        try {
            $record = (new OriginalStorage($this->directory, 104857600))->receive(
                $stream, $this->metadata($jpeg), $this->directory . '/working.jpg', 'large.jpg'
            );
            self::assertSame(strlen($jpeg), $record['size']);
            self::assertSame(hash('sha256', $jpeg), hash_file('sha256', $this->directory . '/working.jpg'));
        } finally {
            fclose($stream);
        }
    }

    public function testInvalidJpegAndPathTraversalAndOversizeAreRejected(): void
    {
        $storage = new OriginalStorage($this->directory, 1024);
        foreach (['not a jpeg', str_repeat('x', 2048)] as $bytes) {
            $stream = $this->stream($bytes);
            try {
                $storage->receive($stream, $this->metadata($bytes), $this->directory . '/working.jpg', 'final.jpg');
                self::fail('Invalid JPEG accepted');
            } catch (CameraException $e) {
                self::assertSame('INVALID_IMAGE', $e->errorCode);
            } finally {
                fclose($stream);
            }
        }
        $this->expectException(CameraException::class);
        OriginalStorage::validateId('../arbitrary-file');
    }

    public function testOriginalCannotBeOverwritten(): void
    {
        $storage = new OriginalStorage($this->directory, 1000000);
        $stream = $this->stream($this->jpeg);
        $storage->receive($stream, $this->metadata($this->jpeg), $this->directory . '/working.jpg', 'final.jpg');
        rewind($stream);
        try {
            $storage->receive($stream, $this->metadata($this->jpeg), $this->directory . '/working.jpg', 'final.jpg');
            self::fail('Existing original overwritten');
        } catch (CameraException $e) {
            self::assertSame('STORAGE_FAILED', $e->errorCode);
        } finally {
            fclose($stream);
        }
    }

    public function testKnownAgentErrorCodesHaveSpecificMessagesAndUnknownCodesAreSanitized(): void
    {
        foreach (CameraException::MESSAGES as $code => $message) {
            self::assertSame($code, (new CameraException($code))->response()['error_code']);
            self::assertSame($message, (new CameraException($code))->getMessage());
        }
        self::assertSame('CAPTURE_FAILED', (new CameraException('sensitive controller detail'))->errorCode);
    }

    public function testLegacyFilenameOnlyDatabaseRemainsReadable(): void
    {
        $database = (new \ReflectionClass(\Photobooth\Service\DatabaseManagerService::class))->newInstanceWithoutConstructor();
        $database->databaseFile = $this->directory . '/db.txt';
        file_put_contents($database->databaseFile, '["old.jpg","old-collage.jpg"]');
        self::assertSame(['old.jpg', 'old-collage.jpg'], $database->getContentFromDB());
        self::assertSame([], (new OriginalStorage($this->directory, 1000000))->forImage('old.jpg'));
    }
}
