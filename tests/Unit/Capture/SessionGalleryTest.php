<?php

namespace Photobooth\Tests\Unit\Capture;

use Photobooth\Service\DatabaseManagerService;
use PHPUnit\Framework\TestCase;

final class SessionGalleryTest extends TestCase
{
    private string $directory;
    private DatabaseManagerService $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sgu-gallery-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        // Isolate from operator config and real gallery entirely.
        $this->database = (new \ReflectionClass(DatabaseManagerService::class))->newInstanceWithoutConstructor();
        $this->database->databaseFile = $this->directory . '/db.txt';
        $this->database->imageDirectory = $this->directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testLegacyFilenameArrayAndDeduplicationRemainCompatible(): void
    {
        file_put_contents($this->database->databaseFile, '["legacy.jpg"]');
        $this->database->appendContentToDB('sgu_final.jpg');
        $this->database->appendContentToDB('sgu_final.jpg');
        self::assertSame(['legacy.jpg', 'sgu_final.jpg'], $this->database->getContentFromDB());
        $this->database->deleteContentFromDB('sgu_final.jpg');
        self::assertSame(['legacy.jpg'], $this->database->getContentFromDB());
        $this->database->deleteContentFromDB('legacy.jpg');
        self::assertFileDoesNotExist($this->database->databaseFile);
    }

    public function testCorruptDatabaseIsNotSilentlyOverwritten(): void
    {
        file_put_contents($this->database->databaseFile, '{broken');
        try {
            $this->database->appendContentToDB('sgu_final.jpg');
            self::fail('Corrupt database was accepted');
        } catch (\JsonException) {
            self::assertSame('{broken', file_get_contents($this->database->databaseFile));
        }
    }

    public function testExplicitRebuildCanRepairCorruptDatabase(): void
    {
        file_put_contents($this->database->databaseFile, '{broken');
        file_put_contents($this->directory . '/legacy.jpg', 'fixture');
        self::assertSame('success', $this->database->rebuildDB());
        self::assertSame(['legacy.jpg'], $this->database->getContentFromDB());
    }
}
