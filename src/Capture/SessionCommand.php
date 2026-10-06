<?php

declare(strict_types=1);

namespace Photobooth\Capture;

/** Runs trusted operator commands with bounded time and output storage. */
final class SessionCommand
{
    public static function run(string $command, int $timeout): int
    {
        $sink = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w');
        if ($sink === false) {
            throw new \RuntimeException('COMMAND_FAILED');
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
        if (!is_resource($process)) {
            fclose($sink);
            throw new \RuntimeException('COMMAND_FAILED');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return $status['exitcode'];
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    // A subprocess may have submitted a physical job: never auto-retry.
                    throw new \RuntimeException('COMMAND_UNCERTAIN');
                }
                usleep(50000);
            } while (true);
        } finally {
            proc_close($process);
            fclose($sink);
        }
    }
}
