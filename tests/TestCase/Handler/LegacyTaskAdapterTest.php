<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Handler;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Handler\LegacyTaskAdapter;
use SpawnQueue\Test\Stub\ExplicitRetryTask;
use SpawnQueue\Test\Stub\NonRetryableTask;
use SpawnQueue\Test\Stub\NoRunTask;
use SpawnQueue\Test\Stub\RetryableTask;
use SpawnQueue\Test\Stub\SuccessTask;
use SpawnQueue\Test\Stub\UnknownErrorTask;
use SpawnQueue\ValueObject\JobData;

class LegacyTaskAdapterTest extends TestCase
{
    private function makeJob(array $payload = [], int $attempts = 1, int $maxAttempts = 5): JobData
    {
        return JobData::fromRow([
            'id'              => '10',
            'queue'           => 'default',
            'job_task'        => 'Stub',
            'data'            => json_encode($payload),
            'failed'          => (string) $attempts,
            'max_attempts'    => (string) $maxAttempts,
            'priority'        => '5',
            'workerkey'       => null,
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ]);
    }

    // ── Static contract ───────────────────────────────────────────────────────

    public function testQueueReturnsDefault(): void
    {
        $this->assertSame('default', LegacyTaskAdapter::queue());
    }

    // ── Success ───────────────────────────────────────────────────────────────

    public function testSuccessTaskReturnsSuccessResult(): void
    {
        $adapter = new LegacyTaskAdapter(SuccessTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertTrue($result->success);
        $this->assertFalse($result->shouldRetry);
        $this->assertNull($result->error);
    }

    // ── RetryableJobException ─────────────────────────────────────────────────

    public function testRetryableExceptionReturnsRetryResult(): void
    {
        $adapter = new LegacyTaskAdapter(RetryableTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        $this->assertTrue($result->shouldRetry);
        $this->assertStringContainsString('temporary failure', (string) $result->error);
    }

    public function testExplicitRetryAfterSecondsIsPreserved(): void
    {
        $adapter = new LegacyTaskAdapter(ExplicitRetryTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        $this->assertTrue($result->shouldRetry);
        $this->assertSame(300, $result->retryAfterSeconds);
    }

    // ── NonRetryableJobException ──────────────────────────────────────────────

    public function testNonRetryableExceptionReturnsFailResult(): void
    {
        $adapter = new LegacyTaskAdapter(NonRetryableTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        $this->assertFalse($result->shouldRetry);
        $this->assertStringContainsString('permanently invalid', (string) $result->error);
    }

    // ── Unknown exceptions treated as retryable ───────────────────────────────

    public function testUnknownExceptionIsRetryable(): void
    {
        $adapter = new LegacyTaskAdapter(UnknownErrorTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        $this->assertTrue($result->shouldRetry, 'Unknown exceptions must be treated as retryable');
        $this->assertStringContainsString('RuntimeException', (string) $result->error);
        $this->assertStringContainsString('unexpected third-party SDK error', (string) $result->error);
    }

    // ── Duck-typing failure ───────────────────────────────────────────────────

    public function testTaskWithoutRunMethodIsRetryable(): void
    {
        $adapter = new LegacyTaskAdapter(NoRunTask::class);
        $result  = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        $this->assertTrue($result->shouldRetry, 'Missing run() must be treated as retryable');
    }

    // ── Class not found ───────────────────────────────────────────────────────

    public function testNonExistentClassReturnsFailResult(): void
    {
        $adapter = new LegacyTaskAdapter('App\\Queue\\Task\\CompletelyNonExistentTask9999');

        // In LegacyTaskAdapter, `new $class()` throws a class-not-found Error,
        // which is caught by the catch(\Throwable) block and treated as retryable.
        $result = $adapter->handle($this->makeJob());

        $this->assertFalse($result->success);
        // Error on class instantiation = treated as retryable (safe default)
        $this->assertTrue($result->shouldRetry);
    }
}
