<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use SpawnQueue\Handler\JobHandlerInterface;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

/**
 * A new-style handler that implements JobHandlerInterface.
 * Returns success, records what it received.
 */
class NewStyleHandler implements JobHandlerInterface
{
    public JobData $receivedJob;

    public static function queue(): string
    {
        return 'emails';
    }

    public function handle(JobData $job): JobResult
    {
        $this->receivedJob = $job;

        return JobResult::success();
    }
}
