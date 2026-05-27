<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueRequeueStuckCommand;

class QueueRequeueStuckCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured. Set DB_TEST_DSN to enable.');
        }

        $this->aliasDefaultToTest();
        $this->ensureTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }

        $this->dropDefaultAlias();
    }

    // ── Delegates to StuckJobResolver ─────────────────────────────────────────

    public function testDelegatesToStuckJobResolverAndRequeuesJob(): void
    {
        // Insert a job stuck for 400s (> 300s threshold). No pid/workerkey so
        // isJobAbandoned() returns true immediately without querying queue_processes.
        $stuckFetched = date('Y-m-d H:i:s', time() - 400);
        $id = $this->insertStuckJob($stuckFetched);

        $code = (new QueueRequeueStuckCommand())->execute(
            new Arguments([], ['queue' => null, 'timeout' => '300'], []),
            $this->makeIo()
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertSame('retry_wait', $this->fetchJob($id)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIo(): ConsoleIo
    {
        return new ConsoleIo(
            new ConsoleOutput('php://memory'),
            new ConsoleOutput('php://memory')
        );
    }

    private function insertStuckJob(string $fetched): int
    {
        $conn = ConnectionManager::get('default');
        $conn->insert('queued_jobs', [
            'queue'        => 'default',
            'job_task'     => 'TestTask',
            'data'         => '{}',
            'status'       => 'processing',
            'failed'       => 1,
            'max_attempts' => 5,
            'priority'     => 5,
            'fetched'      => $fetched,
            'workerkey'    => null,
            'pid'          => null,
            'created'      => date('Y-m-d H:i:s'),
        ]);

        return (int) $conn->lastInsertId();
    }

    private function fetchJob(int $id): array
    {
        return ConnectionManager::get('default')
            ->execute('SELECT * FROM queued_jobs WHERE id = ?', [$id])
            ->fetch('assoc');
    }

    private function aliasDefaultToTest(): void
    {
        if (ConnectionManager::getConfig('default')) {
            ConnectionManager::drop('default');
        }
        ConnectionManager::setConfig('default', ConnectionManager::getConfig('test'));
    }

    private function dropDefaultAlias(): void
    {
        if (ConnectionManager::getConfig('default')) {
            ConnectionManager::drop('default');
        }
    }

    private function ensureTable(): void
    {
        ConnectionManager::get('default')->execute('
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

        // queue_processes is needed by StuckJobResolver/CoordinatorProcessRegistry
        // even though this test uses null workerkey (no registry lookup happens)
        ConnectionManager::get('default')->execute('
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
        $conn = ConnectionManager::get('default');
        $conn->execute('DELETE FROM queued_jobs');
        $conn->execute('DELETE FROM queue_processes');
    }
}
