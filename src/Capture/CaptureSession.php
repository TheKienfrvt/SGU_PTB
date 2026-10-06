<?php

declare(strict_types=1);

namespace Photobooth\Capture;

/** Server authority for transitions and at-most-once physical commands. */
final class CaptureSession
{
    private const PRINT_SHEET_FORMAT = 'A5-pair';

    public function __construct(private SessionStore $store, private string $owner)
    {
    }

    public function status(string $id): array
    {
        return $this->store->locked($id, $this->owner, static fn (array $session): array => $session);
    }

    public function capture(string $id, int $index, callable $capture): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($id, $index, $capture): array {
            if (isset($session['shots'][$index])) {
                return $session; // Lost response / duplicate request: no second shutter.
            }
            if ($session['state'] !== 'preview' || $index !== count($session['shots']) || $index >= $session['settings']['capture_target']) {
                throw new \RuntimeException('INVALID_TRANSITION');
            }
            $shot = bin2hex(random_bytes(16));
            $free = disk_free_space(dirname($this->store->path($id)));
            if ($free === false || $free < $session['settings']['max_bytes'] * 2) {
                throw new \RuntimeException('STORAGE_FULL');
            }
            $session['pending_shot'] = $shot;
            $session['state'] = 'capturing';
            $this->store->save($session); // Durable reservation BEFORE physical side effect.
            try {
                $original = $this->store->path($id, '.' . $shot . '.jpg');
                $capture($original, $shot);
                (new SessionImagePipeline($session['settings']))->thumbnail($original, $this->store->path($id, '.' . $shot . '.thumb.jpg'));
                $session['shots'][] = $shot;
                $session['pending_shot'] = null;
                $session['state'] = count($session['shots']) === $session['settings']['capture_target'] ? 'selecting' : 'preview';
                $this->store->save($session);
                return $session;
            } catch (\Throwable $e) {
                $session['state'] = 'capture-uncertain';
                $this->store->save($session);
                throw $e;
            }
        });
    }

    /** Replace one automatically assigned browser-capture slot without touching the others. */
    public function replace(string $id, int $index, callable $capture): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($id, $index, $capture): array {
            if (($session['settings']['capture_mode'] ?? 'native') !== 'browser'
                || $session['state'] !== 'final-preview'
                || ($session['confirmed'] ?? false) === true
                || !isset($session['shots'][$index], $session['selected'][$index])
                || $session['shots'][$index] !== $session['selected'][$index]) {
                throw new \RuntimeException('INVALID_TRANSITION');
            }
            $shot = bin2hex(random_bytes(16));
            $previous = $session['shots'][$index];
            $free = disk_free_space(dirname($this->store->path($id)));
            if ($free === false || $free < $session['settings']['max_bytes'] * 2) {
                throw new \RuntimeException('STORAGE_FULL');
            }
            $session['pending_shot'] = $shot;
            $session['state'] = 'capturing';
            $this->store->save($session);
            try {
                $original = $this->store->path($id, '.' . $shot . '.jpg');
                $capture($original, $shot);
                (new SessionImagePipeline($session['settings']))->thumbnail(
                    $original,
                    $this->store->path($id, '.' . $shot . '.thumb.jpg')
                );
                $session['shots'][$index] = $shot;
                $session['selected'][$index] = $shot;
                $session['pending_shot'] = null;
                $session['final'] = null;
                $session['final_hash'] = null;
                $session['confirmed'] = false;
                $session['state'] = 'selecting';
                $this->store->save($session);
                foreach (['.' . $previous . '.jpg', '.' . $previous . '.thumb.jpg'] as $suffix) {
                    $path = $this->store->path($id, $suffix);
                    if (is_file($path)) {
                        unlink($path);
                    }
                }

                return $session;
            } catch (\Throwable $e) {
                $session['state'] = 'capture-uncertain';
                $this->store->save($session);
                throw $e;
            }
        });
    }

    public function compose(string $id, array $selected, callable $publish): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($id, $selected, $publish): array {
            if ($session['final'] !== null && $selected === $session['selected']) {
                return $session;
            }
            if (!in_array($session['state'], ['selecting', 'composing'], true) || count($selected) !== $session['settings']['required_slots']
                || count(array_unique($selected, SORT_REGULAR)) !== count($selected)) {
                throw new \RuntimeException('INVALID_SELECTION');
            }
            foreach ($selected as $shot) {
                if (!is_string($shot) || !in_array($shot, $session['shots'], true)) {
                    throw new \RuntimeException('INVALID_SELECTION');
                }
            }
            if ($session['state'] === 'composing' && $selected !== $session['selected']) {
                throw new \RuntimeException('INVALID_SELECTION');
            }
            $session['state'] = 'composing';
            $session['selected'] = $selected;
            $this->store->save($session);
            // Repeatable file operation: same name, no gallery entry for individual shots.
            $filename = 'sgu_' . $id . '.jpg';
            $sources = array_map(fn (string $shot): string => $this->store->path($id, '.' . $shot . '.jpg'), $selected);
            $session['final_hash'] = $publish($sources, $filename, $session['settings']);
            $session['final'] = $filename;
            $session['confirmed'] = false;
            $session['state'] = 'final-preview';
            $this->store->save($session);
            return $session;
        });
    }

    public function confirm(string $id, callable $publish): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($publish): array {
            if (($session['confirmed'] ?? false) === true) {
                return $session;
            }
            if ($session['state'] !== 'final-preview' || !is_string($session['final'] ?? null)
                || !is_string($session['final_hash'] ?? null)) {
                throw new \RuntimeException('INVALID_TRANSITION');
            }
            $publish($session['final'], $session['final_hash']);
            $session['confirmed'] = true;
            $session['state'] = 'confirmed';
            $this->store->save($session);

            return $session;
        });
    }

    public function composePrintSheet(string $id, callable $publish): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($id, $publish): array {
            if (($session['state'] ?? '') === 'sheet-preview'
                && ($session['sheet_format'] ?? null) === self::PRINT_SHEET_FORMAT
                && is_string($session['sheet'] ?? null)
                && is_string($session['sheet_hash'] ?? null)) {
                return $session;
            }
            if (!in_array($session['state'], ['confirmed', 'sheet-preview'], true)
                || ($session['confirmed'] ?? false) !== true
                || !is_string($session['final'] ?? null) || !is_string($session['final_hash'] ?? null)) {
                throw new \RuntimeException('INVALID_TRANSITION');
            }

            $filename = 'sgu_a5_' . $id . '.jpg';
            $session['sheet_hash'] = $publish(
                $session['final'],
                $session['final_hash'],
                $filename,
                $session['settings']
            );
            $session['sheet'] = $filename;
            $session['sheet_format'] = self::PRINT_SHEET_FORMAT;
            $session['state'] = 'sheet-preview';
            $this->store->save($session);

            return $session;
        });
    }

    public function print(string $id, callable $validate, callable $submit): array
    {
        return $this->store->locked($id, $this->owner, function (array $session) use ($validate, $submit): array {
            if ($session['state'] === 'complete') {
                return $session;
            }
            if ($session['state'] !== 'sheet-preview' || ($session['confirmed'] ?? false) !== true
                || ($session['sheet_format'] ?? null) !== self::PRINT_SHEET_FORMAT
                || !is_string($session['sheet'] ?? null) || !is_string($session['sheet_hash'] ?? null)) {
                throw new \RuntimeException('PRINT_UNCERTAIN');
            }
            $validate($session['sheet'], $session['sheet_hash']); // Known pre-submit errors remain retryable.
            $session['state'] = 'printing';
            $this->store->save($session);
            try {
                $submit($session['sheet']);
                $session['state'] = 'complete';
                $this->store->save($session);
                return $session;
            } catch (\Throwable $e) {
                $session['state'] = 'print-uncertain';
                $this->store->save($session);
                throw $e;
            }
        });
    }

    public function cancel(string $id): array
    {
        return $this->store->locked($id, $this->owner, function (array $session): array {
            // Preserve uncertain records for operator inspection, but release this kiosk.
            if (!in_array($session['state'], ['printing', 'capturing', 'print-uncertain', 'capture-uncertain', 'complete'], true)) {
                $session['state'] = 'cancelled';
                $this->store->save($session);
            }
            return $session;
        });
    }
}
