<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueWorkAllCommand;

class QueueWorkAllCommandTest extends TestCase
{
    private mixed $savedQueues;

    protected function setUp(): void
    {
        $this->savedQueues = Configure::read('SpawnQueue.queues');
    }

    protected function tearDown(): void
    {
        Configure::write('SpawnQueue.queues', $this->savedQueues);
    }

    // ── Associative config: keys are queue names ───────────────────────────────

    public function testResolvesAssociativeQueueConfig(): void
    {
        Configure::write('SpawnQueue.queues', [
            'emails'  => ['max_workers' => 4],
            'imports' => ['max_workers' => 2],
        ]);

        $queues = $this->resolveQueueNames();

        $this->assertSame(['emails', 'imports'], $queues);
    }

    // ── Sequential config: values are queue names ──────────────────────────────

    public function testResolvesSequentialQueueConfig(): void
    {
        Configure::write('SpawnQueue.queues', ['emails', 'imports']);

        $queues = $this->resolveQueueNames();

        $this->assertSame(['emails', 'imports'], $queues);
    }

    // ── No queues configured: falls back to 'default' ─────────────────────────

    public function testFallsBackToDefaultWhenNoQueuesConfigured(): void
    {
        Configure::write('SpawnQueue.queues', []);

        $queues = $this->resolveQueueNames();

        $this->assertSame(['default'], $queues);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveQueueNames(): array
    {
        $cmd    = new QueueWorkAllCommand();
        $method = new \ReflectionMethod($cmd, 'resolveQueueNames');
        $method->setAccessible(true);

        return $method->invoke($cmd);
    }
}
