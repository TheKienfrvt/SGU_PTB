<?php

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;

/**
 * Class DatabaseManager
 *
 * Manages the database, including adding and deleting files.
 */
class DatabaseManagerService
{
    public string $databaseFile = '';
    public string $imageDirectory = '';

    public function __construct()
    {
        $config = ConfigurationService::getInstance()->getConfiguration();
        $this->databaseFile = FolderEnum::DATA->absolute() . DIRECTORY_SEPARATOR . $config['database']['file'] . '.txt';
        $this->imageDirectory = FolderEnum::IMAGES->absolute();
    }

    /**
     * Get the list of files from the database file.
     */
    public function getContentFromDB(): array
    {
        // check if the database file is defined and non-empty
        if (!isset($this->databaseFile) || empty($this->databaseFile)) {
            throw new \Exception('Database not defined.');
        }

        try {
            // get data from database
            if (file_exists($this->databaseFile)) {
                $data = file_get_contents($this->databaseFile);
                if ($data === false) {
                    throw new \Exception('Failed to read file: ' . $this->databaseFile);
                }
                $decodedData = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Failed to decode JSON: ' . json_last_error_msg());
                }

                return is_array($decodedData) ? $decodedData : [];
            } else {
                throw new \Exception('File not found: ' . $this->databaseFile);
            }
        } catch (\Exception $e) {
            // do nothing
        }

        return [];
    }

    /**
     * Get the list of images from the images directory.
     */
    public function getFilesFromDirectory(): array
    {
        // check if the directory is defined and non-empty
        if (!isset($this->imageDirectory) || empty($this->imageDirectory)) {
            throw new \Exception('Directory not defined.');
        }

        try {
            // open the directory
            $dh = opendir($this->imageDirectory);
            if ($dh === false) {
                throw new \Exception('Failed to open directory: ' . $this->imageDirectory);
            }

            // read the files in the directory
            $files = [];
            while (false !== ($filename = readdir($dh))) {
                $files[] = $filename;
            }
            closedir($dh);

            // filter the files to include only images with .jpg or .jpeg extensions
            $images = preg_grep('/\.(jpg|jpeg)$/i', $files);
            if ($images === false) {
                return [];
            }

            return $images;
        } catch (\Exception $e) {
            // do nothing
        }

        return [];
    }

    /**
     * Append a new content by name to the database file.
     */
    public function appendContentToDB(string $content): void
    {
        if (!$content) {
            throw new \Exception('Invalid content.');
        }

        // check if the database file is defined and non-empty
        if (!isset($this->databaseFile) || empty($this->databaseFile)) {
            throw new \Exception('Database not defined.');
        }

        $this->updateAtomically(static function (array $files) use ($content): array {
            if (!in_array($content, $files, true)) {
                $files[] = $content;
            }
            return $files;
        });
    }

    /**
     * Delete an entry by name from the database file.
     */
    public function deleteContentFromDB(string $content): void
    {
        if (!$content) {
            throw new \Exception('Invalid filename.');
        }

        // check if the database file is defined and non-empty
        if (!isset($this->databaseFile) || empty($this->databaseFile)) {
            throw new \Exception('Database not defined.');
        }
        $this->updateAtomically(static fn (array $files): array => array_values(array_filter($files, static fn ($file): bool => $file !== $content)));
    }

    /** Preserve the legacy filename-array schema; serialize all writers. */
    private function updateAtomically(callable $change, bool $rebuild = false): void
    {
        $lock = fopen($this->databaseFile . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock gallery database.');
        }
        $temporary = $this->databaseFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock gallery database.');
            }
            $files = !$rebuild && is_file($this->databaseFile)
                ? json_decode((string) file_get_contents($this->databaseFile), true, 512, JSON_THROW_ON_ERROR) : [];
            if (!is_array($files)) {
                throw new \RuntimeException('Invalid gallery database; refusing to overwrite.');
            }
            $files = $change($files);
            $json = json_encode(array_values($files), JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $this->databaseFile)) {
                throw new \RuntimeException('Cannot save gallery database.');
            }
            if ($files === []) {
                unlink($this->databaseFile);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Check if an content exists in the database file.
     */
    public function isInDB(string $content): bool
    {
        if (!$content) {
            throw new \Exception('Invalid filename.');
        }

        // check if the database file is defined and non-empty
        if (!isset($this->databaseFile) || empty($this->databaseFile)) {
            throw new \Exception('Database not defined.');
        }

        $currContent = $this->getContentFromDB();

        return in_array($content, $currContent);
    }

    /**
     * Returns the size of the database file in bytes.
     */
    public function getDBSize(): int
    {
        if (file_exists($this->databaseFile)) {
            return (int) filesize($this->databaseFile);
        }
        return 0;
    }

    /**
     * Rebuilds the image database by scanning the image directory and creating a new database
     * file with the names of all files sorted by modification time.
     *
     * @return string The string "success" if the database was rebuilt successfully, or "error"
     *                if an error occurred during the rebuilding process.
     */
    public function rebuildDB(): string
    {
        // check if the database file is defined and non-empty
        if (!isset($this->databaseFile) || empty($this->databaseFile)) {
            throw new \Exception('Database not defined.');
        }

        // check if the file directory is defined and non-empty
        if (!isset($this->imageDirectory) || empty($this->imageDirectory)) {
            throw new \Exception('File directory not defined.');
        }

        try {
            $this->updateAtomically(function (array $existing): array {
                // Scan while holding the writer lock, preserving chronological rebuild behavior.
                $output = [];
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->imageDirectory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)) as $value) {
                    if ($value->isFile() && strtolower(pathinfo($value->getFilename(), PATHINFO_EXTENSION)) === 'jpg') {
                        $output[] = [$value->getMTime(), $value->getFilename()];
                    }
                }
                usort($output, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                return array_column($output, 1);
            }, true);

            return 'success';
        } catch (\Exception $e) {
            return 'error';
        }
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
