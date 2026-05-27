<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

/**
 * Simulates a task that throws an unexpected exception (not a SpawnQueue exception).
 * The adapter must treat this as retryable.
 */
class UnknownErrorTask
{
    public function run(array $data, int $jobId): void
    {
        throw new \RuntimeException('unexpected third-party SDK error');
    }
}
