<?php

declare(strict_types=1);

namespace SpawnQueue\Exception;

use RuntimeException;

/**
 * Throw this inside a job handler to signal a temporary failure.
 * SpawnQueue will re-queue the job with exponential backoff.
 *
 * Usage:
 *   throw new RetryableJobException('API timeout, will retry');
 *   throw new RetryableJobException('Rate limited', retryAfterSeconds: 300);
 */
class RetryableJobException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly int $retryAfterSeconds = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Explicit delay before the next attempt.
     * 0 = let SpawnQueue calculate the backoff automatically.
     */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
