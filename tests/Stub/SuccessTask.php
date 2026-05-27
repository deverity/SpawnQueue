<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

/**
 * Simulates a dereuromark-style task that completes successfully.
 * Does NOT extend Queue\Queue\Task to avoid a hard dependency in tests.
 */
class SuccessTask
{
    public bool $ran         = false;
    public array $receivedData = [];
    public int $receivedJobId  = 0;

    public function run(array $data, int $jobId): void
    {
        $this->ran           = true;
        $this->receivedData  = $data;
        $this->receivedJobId = $jobId;
    }
}
