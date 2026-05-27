<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\QueueCoordinator;
use SpawnQueue\Coordinator\SuperCoordinator;
use SpawnQueue\ValueObject\QueueConfig;

class SuperCoordinatorTest extends TestCase
{
    public function testMultiplexesTwoQueuesWithDifferentConfigs(): void
    {
        $config1 = QueueConfig::forQueue('default');
        $config2 = QueueConfig::forQueue('emails');
        $super   = new SuperCoordinator([$config1, $config2]);

        $coord1 = $this->createMock(QueueCoordinator::class);
        $coord2 = $this->createMock(QueueCoordinator::class);

        // Both coordinators must be initialized when the super starts
        $coord1->expects($this->once())->method('initialize');
        $coord2->expects($this->once())->method('initialize');
        $coord1->method('logStarted');
        $coord2->method('logStarted');
        $coord1->method('gracefulShutdown');
        $coord2->method('gracefulShutdown');

        $this->injectCoordinators($super, [$coord1, $coord2]);
        $this->set($super, 'shutdown', true); // skip the event loop

        $super->run();
    }

    public function testFullQueueDoesNotBlockOtherQueue(): void
    {
        $config1 = QueueConfig::forQueue('default');
        $config2 = QueueConfig::forQueue('emails');
        $super   = new SuperCoordinator([$config1, $config2]);

        $coord1 = $this->createMock(QueueCoordinator::class);
        $coord2 = $this->createMock(QueueCoordinator::class);

        $coord1->method('initialize');
        $coord2->method('initialize');
        $coord1->method('logStarted');
        $coord2->method('logStarted');
        $coord1->method('gracefulShutdown');
        $coord2->method('gracefulShutdown');
        $coord1->method('initiateShutdown');
        $coord2->method('initiateShutdown');

        $coord1->method('isRunning')->willReturn(true);
        $coord2->method('isRunning')->willReturn(true);

        // coord1 is full/idle — its tick() returns false and does not block coord2
        $coord1->method('tick')->willReturn(false);

        // coord2 must still be ticked; it triggers shutdown to end the loop cleanly
        $coord2->expects($this->once())
               ->method('tick')
               ->willReturnCallback(function () use ($super): bool {
                   $super->initiateShutdown();
                   return true;
               });

        $this->injectCoordinators($super, [$coord1, $coord2]);

        $super->run();
    }

    public function testShutdownDrainsAllQueues(): void
    {
        $config1 = QueueConfig::forQueue('default');
        $config2 = QueueConfig::forQueue('emails');
        $super   = new SuperCoordinator([$config1, $config2]);

        $coord1 = $this->createMock(QueueCoordinator::class);
        $coord2 = $this->createMock(QueueCoordinator::class);

        $coord1->method('initialize');
        $coord2->method('initialize');
        $coord1->method('logStarted');
        $coord2->method('logStarted');

        // Every coordinator must be drained after shutdown
        $coord1->expects($this->once())->method('gracefulShutdown');
        $coord2->expects($this->once())->method('gracefulShutdown');

        $this->injectCoordinators($super, [$coord1, $coord2]);
        $this->set($super, 'shutdown', true);

        $super->run();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param QueueCoordinator[] $coordinators */
    private function injectCoordinators(SuperCoordinator $super, array $coordinators): void
    {
        $this->set($super, 'coordinators', $coordinators);
    }

    private function set(object $obj, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $property);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }
}
