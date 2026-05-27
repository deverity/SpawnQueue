<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\ChildProcessManager;
use SpawnQueue\Coordinator\CoordinatorProcessRegistry;
use SpawnQueue\Coordinator\FailedJobReviver;
use SpawnQueue\Coordinator\JobClaimer;
use SpawnQueue\Coordinator\QueueCoordinator;
use SpawnQueue\Coordinator\StuckJobResolver;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\ValueObject\QueueConfig;

class QueueCoordinatorTest extends TestCase
{
    // ── Claim loop ────────────────────────────────────────────────────────────

    public function testTickClaimsUpToMaxWorkers(): void
    {
        // QueueConfig::forQueue('default') gives max_workers = 2 from bootstrap
        $config = QueueConfig::forQueue('default');
        $coord  = new QueueCoordinator($config);

        $job1 = $this->makeJobData(1);
        $job2 = $this->makeJobData(2);

        $claimer = $this->createMock(JobClaimer::class);
        $claimer->expects($this->exactly(2))
                ->method('claim')
                ->willReturnOnConsecutiveCalls($job1, $job2);

        $childManager = $this->createMock(ChildProcessManager::class);
        $childManager->method('reap');
        $childManager->method('hasSlot')
                     ->willReturnOnConsecutiveCalls(true, true, false);
        $childManager->expects($this->exactly(2))->method('spawn');

        $this->injectAll($coord, $claimer, $childManager);

        $result = $coord->tick(allowSleep: false);

        $this->assertTrue($result);
    }

    public function testTickDoesNotClaimWhenSlotsAreFull(): void
    {
        $config = QueueConfig::forQueue('default');
        $coord  = new QueueCoordinator($config);

        $claimer = $this->createMock(JobClaimer::class);
        $claimer->expects($this->never())->method('claim');

        $childManager = $this->createMock(ChildProcessManager::class);
        $childManager->method('reap');
        $childManager->method('hasSlot')->willReturn(false);

        $this->injectAll($coord, $claimer, $childManager);

        $result = $coord->tick(allowSleep: false);

        $this->assertFalse($result);
    }

    // ── Graceful shutdown ─────────────────────────────────────────────────────

    public function testGracefulShutdownWaitsForChildrenToFinish(): void
    {
        $config = QueueConfig::forQueue('default');
        $coord  = new QueueCoordinator($config);

        $reaped = false;

        $childManager = $this->createMock(ChildProcessManager::class);
        $childManager->method('reap')->willReturnCallback(function () use (&$reaped): void {
            $reaped = true;
        });
        $childManager->method('count')->willReturnCallback(fn () => $reaped ? 0 : 1);
        $childManager->method('terminateAll');
        $childManager->method('killAll');

        $registry = $this->createMock(CoordinatorProcessRegistry::class);
        $registry->expects($this->once())->method('stop');

        $this->injectAll($coord, childManager: $childManager, processRegistry: $registry);

        $coord->gracefulShutdown();

        $this->assertTrue($reaped, 'reap() must be called during graceful shutdown');
    }

    // ── Periodic tasks ────────────────────────────────────────────────────────

    public function testStuckResolverCalledPeriodically(): void
    {
        $config = QueueConfig::forQueue('default');
        $coord  = new QueueCoordinator($config);

        $stuckResolver = $this->createMock(StuckJobResolver::class);
        $stuckResolver->expects($this->once())
                      ->method('resolve')
                      ->willReturn(0);

        $failedReviver = $this->createMock(FailedJobReviver::class);
        $failedReviver->method('revive')->willReturn(0);

        $claimer = $this->createMock(JobClaimer::class);
        $claimer->method('claim')->willReturn(null);

        $childManager = $this->createMock(ChildProcessManager::class);
        $childManager->method('reap');
        $childManager->method('hasSlot')->willReturn(false);

        $registry = $this->createMock(CoordinatorProcessRegistry::class);
        $registry->method('pruneDeadProcesses')->willReturn(0);

        $this->injectAll(
            $coord,
            claimer:        $claimer,
            childManager:   $childManager,
            processRegistry: $registry,
            stuckResolver:  $stuckResolver,
            failedReviver:  $failedReviver,
            lastStuckCheck: 0, // force the periodic check to run
        );

        $coord->tick(allowSleep: false);
    }

    public function testFailedJobReviverCalledPeriodically(): void
    {
        $config = QueueConfig::forQueue('default');
        $coord  = new QueueCoordinator($config);

        $failedReviver = $this->createMock(FailedJobReviver::class);
        $failedReviver->expects($this->once())
                      ->method('revive')
                      ->willReturn(0);

        $stuckResolver = $this->createMock(StuckJobResolver::class);
        $stuckResolver->method('resolve')->willReturn(0);

        $claimer = $this->createMock(JobClaimer::class);
        $claimer->method('claim')->willReturn(null);

        $childManager = $this->createMock(ChildProcessManager::class);
        $childManager->method('reap');
        $childManager->method('hasSlot')->willReturn(false);

        $registry = $this->createMock(CoordinatorProcessRegistry::class);
        $registry->method('pruneDeadProcesses')->willReturn(0);

        $this->injectAll(
            $coord,
            claimer:        $claimer,
            childManager:   $childManager,
            processRegistry: $registry,
            stuckResolver:  $stuckResolver,
            failedReviver:  $failedReviver,
            lastStuckCheck: 0,
        );

        $coord->tick(allowSleep: false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function injectAll(
        QueueCoordinator          $coord,
        ?JobClaimer               $claimer        = null,
        ?ChildProcessManager      $childManager   = null,
        ?CoordinatorProcessRegistry $processRegistry = null,
        ?StuckJobResolver         $stuckResolver  = null,
        ?FailedJobReviver         $failedReviver  = null,
        int                       $lastStuckCheck = -1,
    ): void {
        if ($claimer !== null) {
            $this->set($coord, 'claimer', $claimer);
        }
        if ($childManager !== null) {
            $this->set($coord, 'childManager', $childManager);
        }

        $registry = $processRegistry ?? $this->createMock(CoordinatorProcessRegistry::class);
        $this->set($coord, 'processRegistry', $registry);

        $resolver = $stuckResolver ?? $this->createMock(StuckJobResolver::class);
        $this->set($coord, 'stuckResolver', $resolver);

        $reviver = $failedReviver ?? $this->createMock(FailedJobReviver::class);
        $this->set($coord, 'failedJobReviver', $reviver);

        // Skip heartbeat to avoid DB calls via processRegistry->heartbeat()
        $this->set($coord, 'lastHeartbeatAt', time());

        // lastStuckCheck: -1 means keep default (set to time() to skip check),
        //                  0 means force the check to run this tick.
        $this->set($coord, 'lastStuckCheck', $lastStuckCheck === -1 ? time() : $lastStuckCheck);
    }

    private function set(object $obj, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $property);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function makeJobData(int $id): JobData
    {
        return JobData::fromRow([
            'id'              => $id,
            'queue'           => 'default',
            'job_task'        => 'TestTask',
            'data'            => '{}',
            'failed'          => 0,
            'max_attempts'    => 5,
            'priority'        => 5,
            'status'          => 'processing',
            'workerkey'       => 'test-worker',
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ]);
    }
}
