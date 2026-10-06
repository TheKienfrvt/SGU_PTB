<?php

namespace Photobooth\Capture;

/** Immutable JPEG bytes and additive sidecars; the legacy filename-only DB is unchanged. */
final class OriginalStorage
{
    public function __construct(private string $directory, private int $maxBytes)
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new CameraException('STORAGE_FAILED');
        }
        // Originals and capture metadata are available to PHP, never as public static files.
        if (!is_file($directory . '/.htaccess')
            && file_put_contents($directory . '/.htaccess', "Require all denied\n", LOCK_EX) === false) {
            throw new CameraException('STORAGE_FAILED');
        }
    }

    public static function validateId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new CameraException('CAPTURE_FAILED');
        }
    }

    /** @param resource $input */
    public function receive($input, array $metadata, string $workFile, string $resultFile): array
    {
        $id = (string) ($metadata['capture_id'] ?? '');
        self::validateId($id);
        $hash = $metadata['sha256'] ?? '';
        $size = $metadata['size'] ?? 0;
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !is_int($size) || $size <= 0 || $size > $this->maxBytes) {
            throw new CameraException('INVALID_IMAGE');
        }
        foreach ([$workFile, $resultFile] as $filename) {
            if (!preg_match('/^[a-zA-Z0-9_-]+\.jpg$/D', basename($filename))) {
                throw new CameraException('INVALID_IMAGE');
            }
        }
        $original = $this->directory . '/' . $id . '.jpg';
        $output = @fopen($original, 'xb');
        if ($output === false) {
            throw new CameraException('STORAGE_FAILED');
        }
        $valid = false;
        try {
            $bytes = stream_copy_to_stream($input, $output, $this->maxBytes + 1);
            fflush($output);
            fclose($output);
            $output = null;
            if ($bytes !== $size || !hash_equals($hash, (string) hash_file('sha256', $original))) {
                throw new CameraException('INTEGRITY_FAILED');
            }
            $image = @getimagesize($original);
            $tail = fopen($original, 'rb');
            $hasEndMarker = false;
            if ($tail !== false) {
                fseek($tail, -2, SEEK_END);
                $hasEndMarker = fread($tail, 2) === "\xff\xd9";
                fclose($tail);
            }
            if ($image === false || $image[2] !== IMAGETYPE_JPEG || $image[0] < 1 || $image[1] < 1
                || !$hasEndMarker
                || (new \finfo(FILEINFO_MIME_TYPE))->file($original) !== 'image/jpeg') {
                throw new CameraException('INVALID_IMAGE');
            }
            if (!chmod($original, 0440)) {
                throw new CameraException('STORAGE_FAILED');
            }
            $valid = true;
            $record = [
                'capture_id' => $id, 'original' => 'original/' . $id . '.jpg',
                'sha256' => $hash, 'size' => $size, 'width' => $image[0], 'height' => $image[1],
                'file' => basename($resultFile), 'work_file' => basename($workFile),
                'created_at' => gmdate(DATE_ATOM),
            ];
            $sidecar = @fopen($this->directory . '/' . $id . '.json', 'xb');
            if ($sidecar === false) {
                throw new CameraException('STORAGE_FAILED');
            }
            try {
                $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                if (fwrite($sidecar, $json) !== strlen($json)) {
                    throw new CameraException('STORAGE_FAILED');
                }
            } finally {
                fclose($sidecar);
            }
            if (!copy($original, $workFile) || !hash_equals($hash, (string) hash_file('sha256', $workFile))) {
                throw new CameraException('STORAGE_FAILED');
            }
            return $record;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (!$valid && is_file($original)) {
                @unlink($original);
            }
        }
    }

    public function forImage(string $file): array
    {
        $records = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $record = json_decode((string) file_get_contents($path), true);
            if (is_array($record) && ($record['file'] ?? null) === $file) {
                $records[] = $record;
            }
        }
        return $records;
    }
}
