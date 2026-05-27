<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use SpawnQueue\Exception\NonRetryableJobException;

/**
 * Simulates a task that throws a NonRetryableJobException.
 */
class NonRetryableTask
{
    public function run(array $data, int $jobId): void
    {
        throw new NonRetryableJobException('payload is permanently invalid');
    }
}
