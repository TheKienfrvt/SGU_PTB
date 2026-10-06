<?php

declare(strict_types=1);

namespace Photobooth\Capture;

/** Private session files, separate from the gallery's filename array. */
final class SessionStore
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('SESSION_STORAGE');
        }
    }

    public static function validateId(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new \RuntimeException('INVALID_SESSION');
        }
    }

    public function path(string $id, string $suffix = '.json'): string
    {
        self::validateId($id);
        if (!preg_match('/\A\.[a-z0-9.]+\z/D', $suffix)) {
            throw new \RuntimeException('INVALID_SESSION');
        }
        return $this->directory . '/' . $id . $suffix;
    }

    public function create(string $id, string $owner, array $settings, ?string $templateId = null): array
    {
        return $this->locked($id, $owner, function (?array $session) use ($id, $owner, $settings, $templateId): array {
            if ($session !== null) {
                return $session;
            }
            $session = ['schema_version' => 1, 'id' => $id, 'owner' => $owner,
                'created_at' => time(), 'expires_at' => time() + $settings['session_hours'] * 3600,
                'state' => 'preview', 'settings' => $settings, 'shots' => [], 'selected' => [], 'final' => null,
                'confirmed' => false, 'sheet' => null, 'sheet_hash' => null, 'sheet_format' => null];
            // Keep the property absent for legacy sessions created before frame selection.
            if ($templateId !== null) {
                $session['template_id'] = $templateId;
            }
            $this->save($session);
            return $session;
        }, true);
    }

    /** The stable lock file is never replaced; only the JSON is atomically renamed. */
    public function locked(string $id, string $owner, callable $action, bool $create = false): mixed
    {
        $lock = fopen($this->path($id, '.lock'), 'c');
        if ($lock === false) {
            throw new \RuntimeException('SESSION_STORAGE');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('SESSION_BUSY');
            }
            $file = $this->path($id);
            $session = null;
            if (is_file($file)) {
                $session = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($session) || !hash_equals($session['owner'], $owner)) {
                    throw new \RuntimeException('INVALID_SESSION');
                }
                if ($session['expires_at'] < time()) {
                    throw new \RuntimeException('SESSION_EXPIRED');
                }
            } elseif (!$create) {
                throw new \RuntimeException('INVALID_SESSION');
            }
            return $action($session);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Call only while holding this session's lock. */
    public function save(array $session): void
    {
        $file = $this->path($session['id']);
        $temporary = $file . '.' . bin2hex(random_bytes(8));
        try {
            $json = json_encode($session, JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $file)) {
                throw new \RuntimeException('SESSION_STORAGE');
            }
            chmod($file, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Delete only expired, unlocked session-owned files; never published finals or gallery files. */
    public function cleanup(int $limit = 20): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id) || is_link($file)) {
                continue;
            }
            $lock = fopen($this->path($id, '.lock'), 'c');
            if ($lock === false) {
                continue;
            }
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $session = json_decode((string) file_get_contents($file), true);
                if (!is_array($session) || ($session['expires_at'] ?? PHP_INT_MAX) >= time()) {
                    continue;
                }
                // Preserve uncertain physical operations for operator reconciliation.
                if (in_array($session['state'] ?? '', ['capturing', 'printing', 'print-uncertain', 'capture-uncertain'], true)) {
                    continue;
                }
                foreach (glob($this->directory . '/' . $id . '.*') ?: [] as $owned) {
                    if (preg_match('/\A' . $id . '\.(json|[a-f0-9]{32}(\.thumb)?\.jpg)\z/D', basename($owned)) && !is_link($owned)) {
                        unlink($owned);
                    }
                }
                // Retain the tiny lock inode to avoid races with another request.
                if (++$removed >= $limit) {
                    break;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        return $removed;
    }
}
