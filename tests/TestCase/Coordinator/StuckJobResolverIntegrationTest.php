<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\StuckJobResolver;

class StuckJobResolverIntegrationTest extends TestCase
{
    private StuckJobResolver $resolver;

    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured.');
        }

        $this->resolver = new StuckJobResolver('test');
        $this->ensureTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }
    }

    // ── No stuck jobs ─────────────────────────────────────────────────────────

    public function testReturnsZeroWhenNothingIsStuck(): void
    {
        $recovered = $this->resolver->resolve('default', 300);

        $this->assertSame(0, $recovered);
    }

    // ── Re-queues stuck job that has attempts remaining ───────────────────────

    public function testStuckJobWithAttemptsRemainingIsRequeued(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400); // stuck for 400s > 300s threshold
        $id           = $this->insertProcessingJob($stuckFetched, attempts: 2, maxAttempts: 5);

        $recovered = $this->resolver->resolve('default', 300);

        $this->assertSame(1, $recovered);

        $row = $this->fetchJob($id);
        $this->assertSame('retry_wait', $row['status']);
        $this->assertNull($row['fetched'], 'fetched must be cleared on re-queue');
        $this->assertNotNull($row['notbefore'], 'notbefore must be set to future');
    }

    // ── Marks dead when attempts exhausted ────────────────────────────────────

    public function testStuckJobWithExhaustedAttemptsIsMarkedDead(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);
        $id           = $this->insertProcessingJob($stuckFetched, attempts: 5, maxAttempts: 5);

        $this->resolver->resolve('default', 300);

        $row = $this->fetchJob($id);
        $this->assertSame('dead', $row['status']);
        $this->assertNotNull($row['failed_at']);
    }

    // ── Fresh processing job is not touched ───────────────────────────────────

    public function testRecentlyClaimedJobIsNotTouched(): void
    {
        $recentFetched = date('Y-m-d H:i:s', time() - 60); // only 60s ago < 300s threshold
        $id            = $this->insertProcessingJob($recentFetched, attempts: 1, maxAttempts: 5);

        $recovered = $this->resolver->resolve('default', 300);

        $this->assertSame(0, $recovered);

        $row = $this->fetchJob($id);
        $this->assertSame('processing', $row['status'], 'Recent job must remain processing');
    }

    // ── Queue filter ──────────────────────────────────────────────────────────

    public function testOnlyTargetQueueIsResolved(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);

        $emailsId  = $this->insertProcessingJob($stuckFetched, attempts: 1, maxAttempts: 5, queue: 'emails');
        $importsId = $this->insertProcessingJob($stuckFetched, attempts: 1, maxAttempts: 5, queue: 'imports');

        // Resolve only emails queue
        $recovered = $this->resolver->resolve('emails', 300);

        $this->assertSame(1, $recovered);
        $this->assertSame('retry_wait', $this->fetchJob($emailsId)['status']);
        $this->assertSame('processing', $this->fetchJob($importsId)['status'], 'imports must be untouched');
    }

    // ── Multiple stuck jobs ───────────────────────────────────────────────────

    public function testMultipleStuckJobsAreAllRecovered(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);

        $this->insertProcessingJob($stuckFetched, attempts: 1, maxAttempts: 5);
        $this->insertProcessingJob($stuckFetched, attempts: 2, maxAttempts: 5);
        $this->insertProcessingJob($stuckFetched, attempts: 3, maxAttempts: 5);

        $recovered = $this->resolver->resolve('default', 300);

        $this->assertSame(3, $recovered);
    }

    // ── Null-queue jobs resolved by default coordinator ───────────────────────

    public function testNullQueueJobResolvedByDefaultCoordinator(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);
        $id = $this->insertProcessingJob($stuckFetched, attempts: 1, maxAttempts: 5, queue: null, workerkey: null);

        $recovered = $this->resolver->resolve('default', 300);

        $this->assertSame(1, $recovered);
        $this->assertSame('retry_wait', $this->fetchJob($id)['status']);
    }

    // ── Star queue scans all queues ───────────────────────────────────────────

    public function testStarQueueScansAllQueues(): void
    {
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);

        $emailsId  = $this->insertProcessingJob($stuckFetched, 1, 5, queue: 'emails',   workerkey: null);
        $importsId = $this->insertProcessingJob($stuckFetched, 1, 5, queue: 'imports', workerkey: null);

        $recovered = $this->resolver->resolve('*', 300);

        $this->assertSame(2, $recovered);
        $this->assertSame('retry_wait', $this->fetchJob($emailsId)['status']);
        $this->assertSame('retry_wait', $this->fetchJob($importsId)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertProcessingJob(
        string  $fetched,
        int     $attempts,
        int     $maxAttempts,
        ?string $queue     = 'default',
        ?string $workerkey = 'worker-test'
    ): int {
        $conn = ConnectionManager::get('test');
        $now  = date('Y-m-d H:i:s');

        $conn->insert('queued_jobs', [
            'queue'        => $queue,
            'job_task'     => 'SpawnQueue\\Test\\Stub\\SuccessTask',
            'data'         => json_encode([]),
            'status'       => 'processing',
            'priority'     => 5,
            'failed'       => $attempts,
            'max_attempts' => $maxAttempts,
            'fetched'      => $fetched,
            'notbefore'    => null,
            'completed'    => null,
            'workerkey'    => $workerkey,
            'pid'          => null,
            'created'      => $now,
        ]);

        return (int) $conn->lastInsertId();
    }

    private function fetchJob(int $id): array
    {
        return ConnectionManager::get('test')
            ->execute('SELECT * FROM queued_jobs WHERE id = ?', [$id])
            ->fetch('assoc');
    }

    private function ensureTable(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->execute('
            CREATE TABLE IF NOT EXISTS queued_jobs (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                queue        VARCHAR(100)  NULL,
                job_task     VARCHAR(200)  NOT NULL,
                data         TEXT          NULL,
                job_group    VARCHAR(255)  NULL,
                reference    VARCHAR(255)  NULL,
                created      DATETIME      NOT NULL,
                notbefore    DATETIME      NULL,
                fetched      DATETIME      NULL,
                completed    DATETIME      NULL,
                progress     FLOAT         NULL,
                failed       INT           NOT NULL DEFAULT 0,
                max_attempts INT           NOT NULL DEFAULT 5,
                failure_message TEXT       NULL,
                workerkey    VARCHAR(100)  NULL,
                pid          INT           NULL,
                status       VARCHAR(50)   NULL DEFAULT \'pending\',
                priority     INT           NOT NULL DEFAULT 5,
                failed_at    DATETIME      NULL,
                INDEX idx_claim (queue, status, notbefore)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $conn->execute('
            CREATE TABLE IF NOT EXISTS queue_processes (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                worker_id    VARCHAR(100)  NOT NULL,
                queue        VARCHAR(100)  NOT NULL,
                host         VARCHAR(255)  NOT NULL,
                pid          INT           NULL,
                status       VARCHAR(50)   NOT NULL DEFAULT \'running\',
                started_at   DATETIME      NULL,
                heartbeat_at DATETIME      NULL,
                stopped_at   DATETIME      NULL,
                created      DATETIME      NOT NULL,
                modified     DATETIME      NOT NULL,
                UNIQUE KEY uq_worker_id (worker_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function truncate(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->execute('DELETE FROM queued_jobs');
        $conn->execute('DELETE FROM queue_processes');
    }
}
