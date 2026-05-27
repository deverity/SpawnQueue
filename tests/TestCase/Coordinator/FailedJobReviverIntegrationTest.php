<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\FailedJobReviver;

class FailedJobReviverIntegrationTest extends TestCase
{
    private FailedJobReviver $reviver;

    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured. Set DB_TEST_DSN to enable integration tests.');
        }

        $this->reviver = new FailedJobReviver('test');
        $this->ensureJobsTable();
        $this->ensureProcessesTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }
    }

    // ── Dead coordinator — job is revived ─────────────────────────────────────

    public function testFailedJobWithDeadCoordinatorIsRevived(): void
    {
        $id = $this->insertFailedJob('default', 'dead-worker');
        // 'dead-worker' has no queue_processes row → isWorkerAlive returns false

        $revived = $this->reviver->revive('default', 60);

        $this->assertSame(1, $revived);
        $this->assertSame('retry_wait', $this->fetchJob($id)['status']);
    }

    // ── Live coordinator — job is left alone ──────────────────────────────────

    public function testFailedJobWithLiveCoordinatorIsNotRevived(): void
    {
        $id = $this->insertFailedJob('default', 'live-worker');
        $this->registerProcess('live-worker', 'default');

        $revived = $this->reviver->revive('default', 60);

        $this->assertSame(0, $revived);
        $this->assertSame('failed', $this->fetchJob($id)['status']);
    }

    // ── Queue filter ──────────────────────────────────────────────────────────

    public function testReviverFiltersQueue(): void
    {
        $emailsId  = $this->insertFailedJob('emails', null);
        $importsId = $this->insertFailedJob('imports', null);

        $revived = $this->reviver->revive('emails', 60);

        $this->assertSame(1, $revived);
        $this->assertSame('retry_wait', $this->fetchJob($emailsId)['status']);
        $this->assertSame('failed',     $this->fetchJob($importsId)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertFailedJob(string $queue, ?string $workerkey): int
    {
        $conn = ConnectionManager::get('test');
        $now  = date('Y-m-d H:i:s');

        $conn->insert('queued_jobs', [
            'queue'        => $queue,
            'job_task'     => 'SpawnQueue\\Test\\Stub\\SuccessTask',
            'data'         => json_encode([]),
            'status'       => 'failed',
            'priority'     => 5,
            'failed'       => 2,
            'max_attempts' => 5,
            'workerkey'    => $workerkey,
            'pid'          => null,
            'notbefore'    => null,
            'fetched'      => null,
            'completed'    => null,
            'created'      => $now,
        ]);

        return (int) $conn->lastInsertId();
    }

    /**
     * Registers a process with a fresh heartbeat and null pid.
     * With pid=null the process check is skipped, so a recent heartbeat alone
     * makes isWorkerAlive() return true.
     */
    private function registerProcess(string $workerId, string $queue): void
    {
        $conn = ConnectionManager::get('test');
        $now  = date('Y-m-d H:i:s');

        $conn->insert('queue_processes', [
            'worker_id'    => $workerId,
            'queue'        => $queue,
            'host'         => 'test-host',
            'pid'          => null,
            'status'       => 'running',
            'started_at'   => $now,
            'heartbeat_at' => $now,
            'stopped_at'   => null,
            'created'      => $now,
            'modified'     => $now,
        ]);
    }

    private function fetchJob(int $id): array
    {
        return ConnectionManager::get('test')
            ->execute('SELECT * FROM queued_jobs WHERE id = ?', [$id])
            ->fetch('assoc');
    }

    private function ensureJobsTable(): void
    {
        ConnectionManager::get('test')->execute('
            CREATE TABLE IF NOT EXISTS queued_jobs (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                queue           VARCHAR(100)  NULL,
                job_task        VARCHAR(200)  NOT NULL,
                data            TEXT          NULL,
                job_group       VARCHAR(255)  NULL,
                reference       VARCHAR(255)  NULL,
                created         DATETIME      NOT NULL,
                notbefore       DATETIME      NULL,
                fetched         DATETIME      NULL,
                completed       DATETIME      NULL,
                progress        FLOAT         NULL,
                failed          INT           NOT NULL DEFAULT 0,
                max_attempts    INT           NOT NULL DEFAULT 5,
                failure_message TEXT          NULL,
                workerkey       VARCHAR(100)  NULL,
                pid             INT           NULL,
                status          VARCHAR(50)   NULL DEFAULT \'pending\',
                priority        INT           NOT NULL DEFAULT 5,
                failed_at       DATETIME      NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function ensureProcessesTable(): void
    {
        ConnectionManager::get('test')->execute('
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
