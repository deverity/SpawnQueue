<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use SpawnQueue\Exception\RetryableJobException;

class ExplicitRetryTask
{
    public function run(array $data, int $jobId): void
    {
        throw new RetryableJobException('rate limited', retryAfterSeconds: 300);
    }
}
