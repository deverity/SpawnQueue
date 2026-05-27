<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use SpawnQueue\Handler\JobHandlerInterface;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

class UndefinedQueueHandler implements JobHandlerInterface
{
    public static function queue(): string
    {
        return 'undefined';
    }

    public function handle(JobData $job): JobResult
    {
        return JobResult::success();
    }
}
