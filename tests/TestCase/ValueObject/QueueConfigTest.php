<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\ValueObject;

use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;
use SpawnQueue\ValueObject\QueueConfig;

/**
 * Tests that QueueConfig correctly merges per-queue overrides with global defaults.
 * The bootstrap already writes a base SpawnQueue config — tests add/override on top.
 */
class QueueConfigTest extends TestCase
{
    // ── Per-queue config declared in bootstrap ────────────────────────────────

    public function testReadsPerQueueMaxWorkers(): void
    {
        $config = QueueConfig::forQueue('emails');

        $this->assertSame(4, $config->maxWorkers);
    }

    public function testReadsPerQueueTimeout(): void
    {
        $config = QueueConfig::forQueue('emails');

        $this->assertSame(60, $config->timeout);
    }

    public function testReadsPerQueueMaxAttempts(): void
    {
        $config = QueueConfig::forQueue('emails');

        $this->assertSame(5, $config->maxAttempts);
    }

    // ── Queue not declared: falls back to global defaults ────────────────────

    public function testUndeclaredQueueUsesGlobalDefaultTimeout(): void
    {
        $config = QueueConfig::forQueue('webhooks'); // not in bootstrap config

        $this->assertSame(30, $config->timeout, 'Should fall back to global default_timeout');
    }

    public function testUndeclaredQueueUsesGlobalDefaultMaxAttempts(): void
    {
        $config = QueueConfig::forQueue('webhooks');

        $this->assertSame(5, $config->maxAttempts);
    }

    public function testUndeclaredQueueUsesHardcodedMaxWorkersFallback(): void
    {
        $config = QueueConfig::forQueue('webhooks');

        $this->assertSame(3, $config->maxWorkers, 'Hard-coded fallback is 3');
    }

    // ── Global settings ───────────────────────────────────────────────────────

    public function testReadsPollInterval(): void
    {
        $config = QueueConfig::forQueue('default');

        $this->assertSame(1.0, $config->pollInterval);
    }

    public function testReadsShutdownTimeout(): void
    {
        $config = QueueConfig::forQueue('default');

        $this->assertSame(5, $config->shutdownTimeout);
    }

    public function testReadsStuckJobTimeout(): void
    {
        $config = QueueConfig::forQueue('default');

        $this->assertSame(300, $config->stuckJobTimeout);
    }

    public function testReadsStuckCheckInterval(): void
    {
        $config = QueueConfig::forQueue('default');

        $this->assertSame(60, $config->stuckCheckInterval);
    }

    // ── queue property is set correctly ──────────────────────────────────────

    public function testQueuePropertyMatchesArgument(): void
    {
        $config = QueueConfig::forQueue('fast');

        $this->assertSame('fast', $config->queue);
    }

    // ── Partial per-queue config ──────────────────────────────────────────────

    public function testQueueWithOnlyMaxWorkersUsesGlobalDefaultsForRest(): void
    {
        Configure::write('SpawnQueue.queues.partial_test', ['max_workers' => 7]);

        $config = QueueConfig::forQueue('partial_test');

        $this->assertSame(7, $config->maxWorkers, 'Per-queue override is applied');
        $this->assertSame(30, $config->timeout, 'Falls back to global default_timeout');
        $this->assertSame(5, $config->maxAttempts, 'Falls back to global default_max_attempts');

        Configure::delete('SpawnQueue.queues.partial_test');
    }

    public function testZeroMaxWorkersIsAccepted(): void
    {
        Configure::write('SpawnQueue.queues.zero_test', ['max_workers' => 0]);

        $config = QueueConfig::forQueue('zero_test');

        $this->assertSame(0, $config->maxWorkers, 'Zero is stored as-is; no validation or clamping');

        Configure::delete('SpawnQueue.queues.zero_test');
    }

    // ── Runtime override ──────────────────────────────────────────────────────

    public function testRuntimeConfigureWriteIsReflected(): void
    {
        Configure::write('SpawnQueue.queues.runtime_test', [
            'max_workers'  => 99,
            'timeout'      => 999,
            'max_attempts' => 1,
        ]);

        $config = QueueConfig::forQueue('runtime_test');

        $this->assertSame(99, $config->maxWorkers);
        $this->assertSame(999, $config->timeout);
        $this->assertSame(1, $config->maxAttempts);

        // Cleanup — restore original config slice
        Configure::delete('SpawnQueue.queues.runtime_test');
    }
}
