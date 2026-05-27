<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use SpawnQueue\Exception\RetryableJobException;

/**
 * Simulates a task that throws a RetryableJobException.
 */
class RetryableTask
{
    public function __construct(private readonly int $delay = 0) {}

    public function run(array $data, int $jobId): void
    {
        throw new RetryableJobException('temporary failure', retryAfterSeconds: $this->delay);
    }
}
