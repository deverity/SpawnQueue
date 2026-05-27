<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Exception;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;

class RetryableJobExceptionTest extends TestCase
{
    public function testDefaultRetryAfterIsZero(): void
    {
        $e = new RetryableJobException('something failed');

        $this->assertSame(0, $e->getRetryAfterSeconds());
    }

    public function testCustomRetryAfterIsStored(): void
    {
        $e = new RetryableJobException('rate limited', retryAfterSeconds: 300);

        $this->assertSame(300, $e->getRetryAfterSeconds());
    }

    public function testMessageIsSet(): void
    {
        $e = new RetryableJobException('SMTP timeout');

        $this->assertSame('SMTP timeout', $e->getMessage());
    }

    public function testPreviousExceptionIsForwarded(): void
    {
        $cause = new \RuntimeException('root cause');
        $e     = new RetryableJobException('wrapped', previous: $cause);

        $this->assertSame($cause, $e->getPrevious());
    }

    public function testIsInstanceOfRuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new RetryableJobException('x'));
    }

    // ── NonRetryableJobException ──────────────────────────────────────────────

    public function testNonRetryableIsInstanceOfRuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new NonRetryableJobException('fatal'));
    }

    public function testNonRetryableMessageIsSet(): void
    {
        $e = new NonRetryableJobException('invalid payload structure');

        $this->assertSame('invalid payload structure', $e->getMessage());
    }
}
