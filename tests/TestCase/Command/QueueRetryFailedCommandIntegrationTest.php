<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueRetryFailedCommand;

class QueueRetryFailedCommandIntegrationTest extends TestCase
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

    // ── Retry failed jobs ─────────────────────────────────────────────────────

    public function testRetryFailedJobsBecomePending(): void
    {
        $id = $this->insertJob('default', 'failed');

        $code = (new QueueRetryFailedCommand())->execute(
            new Arguments([], ['queue' => null, 'status' => 'failed', 'limit' => '100'], []),
            $this->makeIo()
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertSame('pending', $this->fetchJob($id)['status']);
        $this->assertSame('0', (string) $this->fetchJob($id)['failed']);
    }

    // ── Retry dead jobs ───────────────────────────────────────────────────────

    public function testRetryDeadJobsBecomePending(): void
    {
        $id = $this->insertJob('default', 'dead');

        $code = (new QueueRetryFailedCommand())->execute(
            new Arguments([], ['queue' => null, 'status' => 'dead', 'limit' => '100'], []),
            $this->makeIo()
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertSame('pending', $this->fetchJob($id)['status']);
    }

    // ── Filter by status, queue and limit ─────────────────────────────────────

    public function testFiltersStatusQueueAndLimit(): void
    {
        $emailId1  = $this->insertJob('emails', 'failed');
        $emailId2  = $this->insertJob('emails', 'failed');
        $defaultId = $this->insertJob('default', 'failed');

        // Retry only 'emails' queue, limit to 1
        (new QueueRetryFailedCommand())->execute(
            new Arguments([], ['queue' => 'emails', 'status' => 'failed', 'limit' => '1'], []),
            $this->makeIo()
        );

        $statuses = [
            $this->fetchJob($emailId1)['status'],
            $this->fetchJob($emailId2)['status'],
        ];

        // Exactly 1 of the 2 emails jobs should be pending (limit=1)
        $pendingCount = count(array_filter($statuses, fn($s) => $s === 'pending'));
        $this->assertSame(1, $pendingCount, 'Limit 1 must retry exactly one job');

        // default queue job must be untouched (different queue)
        $this->assertSame('failed', $this->fetchJob($defaultId)['status'], 'Default queue job must not be touched');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIo(): ConsoleIo
    {
        return new ConsoleIo(
            new ConsoleOutput('php://memory'),
            new ConsoleOutput('php://memory')
        );
    }

    private function insertJob(string $queue, string $status): int
    {
        $conn = ConnectionManager::get('default');
        $conn->insert('queued_jobs', [
            'queue'        => $queue,
            'job_task'     => 'TestTask',
            'data'         => '{}',
            'status'       => $status,
            'failed'       => 3,
            'max_attempts' => 5,
            'priority'     => 5,
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
    }

    private function truncate(): void
    {
        ConnectionManager::get('default')->execute('DELETE FROM queued_jobs');
    }
}
