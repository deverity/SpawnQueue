<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Worker;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Worker\JobResult;

class JobResultTest extends TestCase
{
    // ── success ───────────────────────────────────────────────────────────────

    public function testSuccessIsSuccessful(): void
    {
        $result = JobResult::success();

        $this->assertTrue($result->success);
    }

    public function testSuccessDoesNotRetry(): void
    {
        $result = JobResult::success();

        $this->assertFalse($result->shouldRetry);
    }

    public function testSuccessHasNoError(): void
    {
        $result = JobResult::success();

        $this->assertNull($result->error);
    }

    public function testSuccessHasZeroRetryDelay(): void
    {
        $result = JobResult::success();

        $this->assertSame(0, $result->retryAfterSeconds);
    }

    // ── retry ─────────────────────────────────────────────────────────────────

    public function testRetryIsNotSuccessful(): void
    {
        $result = JobResult::retry('timeout error');

        $this->assertFalse($result->success);
    }

    public function testRetryShouldRetry(): void
    {
        $result = JobResult::retry('timeout error');

        $this->assertTrue($result->shouldRetry);
    }

    public function testRetryContainsError(): void
    {
        $result = JobResult::retry('connection refused');

        $this->assertSame('connection refused', $result->error);
    }

    public function testRetryDefaultDelayIsZero(): void
    {
        $result = JobResult::retry('error');

        $this->assertSame(0, $result->retryAfterSeconds, 'Zero means use automatic backoff');
    }

    public function testRetryWithExplicitDelay(): void
    {
        $result = JobResult::retry('rate limited', 300);

        $this->assertSame(300, $result->retryAfterSeconds);
    }

    public function testRetryWithNegativeDelayIsStoredAsIs(): void
    {
        // JobResult performs no clamping — callers own the value.
        $result = JobResult::retry('error', -1);

        $this->assertSame(-1, $result->retryAfterSeconds);
    }

    // ── fail ─────────────────────────────────────────────────────────────────

    public function testFailIsNotSuccessful(): void
    {
        $result = JobResult::fail('invalid payload');

        $this->assertFalse($result->success);
    }

    public function testFailDoesNotRetry(): void
    {
        $result = JobResult::fail('invalid payload');

        $this->assertFalse($result->shouldRetry);
    }

    public function testFailContainsError(): void
    {
        $result = JobResult::fail('handler class not found');

        $this->assertSame('handler class not found', $result->error);
    }

    // ── immutability ──────────────────────────────────────────────────────────

    public function testResultIsImmutable(): void
    {
        $result = JobResult::success();

        // Readonly properties cannot be modified after construction.
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $result->success = false;
    }
}
