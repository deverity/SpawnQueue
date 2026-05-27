<?php

declare(strict_types=1);

namespace SpawnQueue\Worker;

/**
 * Immutable result returned by a job handler.
 * The JobRunner uses this to decide the final database status of the job.
 */
final class JobResult
{
    private function __construct(
        public readonly bool    $success,
        public readonly bool    $shouldRetry,
        public readonly ?string $error = null,
        public readonly int     $retryAfterSeconds = 0,
    ) {}

    public static function success(): self
    {
        return new self(true, false);
    }

    /**
     * @param int $retryAfterSeconds 0 = SpawnQueue calculates backoff automatically.
     */
    public static function retry(string $error, int $retryAfterSeconds = 0): self
    {
        return new self(false, true, $error, $retryAfterSeconds);
    }

    public static function fail(string $error): self
    {
        return new self(false, false, $error);
    }
}
