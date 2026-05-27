<?php

declare(strict_types=1);

namespace SpawnQueue\Utility;

/**
 * Cross-platform helper to check whether a local process is still running.
 */
final class ProcessChecker
{
    /**
     * Returns true when the process with the given PID exists on this host.
     *
     * On Unix systems the POSIX extension is used (signal 0 — probe only).
     * On Windows the tasklist command is used as a fallback.
     */
    public static function isAlive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $exitCode = 0;
            @exec(sprintf('tasklist /FI "PID eq %d" /FO CSV /NH', $pid), $output, $exitCode);

            if ($exitCode !== 0) {
                return false;
            }

            $line = trim(implode("\n", $output));
            if ($line === ''
                || stripos($line, 'No tasks are running') !== false
                || stripos($line, 'INFO: No tasks') !== false
            ) {
                return false;
            }

            return str_contains($line, '"' . $pid . '"');
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
