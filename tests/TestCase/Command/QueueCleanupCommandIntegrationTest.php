<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueCleanupCommand;

class QueueCleanupCommandIntegrationTest extends TestCase
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

    // ── Old terminal jobs are removed ─────────────────────────────────────────

    public function testRemovesOldTerminalJobs(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-60 days'));

        $doneId   = $this->insertJobWithCreated('done',   $oldDate);
        $failedId = $this->insertJobWithCreated('failed', $oldDate);
        $deadId   = $this->insertJobWithCreated('dead',   $oldDate);

        $code = (new QueueCleanupCommand())->execute(
            new Arguments([], ['days' => '30', 'status' => null], []),
            $this->makeIo()
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertNull($this->fetchJob($doneId), 'done job older than threshold must be deleted');
        $this->assertNull($this->fetchJob($failedId), 'failed job older than threshold must be deleted');
        $this->assertNull($this->fetchJob($deadId), 'dead job older than threshold must be deleted');
    }

    // ── Recent terminal jobs are kept ─────────────────────────────────────────

    public function testDoesNotRemoveRecentJobs(): void
    {
        $recentDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        $id = $this->insertJobWithCreated('done', $recentDate);

        $code = (new QueueCleanupCommand())->execute(
            new Arguments([], ['days' => '30', 'status' => null], []),
            $this->makeIo()
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertNotNull($this->fetchJob($id), 'job created 1 day ago must not be deleted');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIo(): ConsoleIo
    {
        return new ConsoleIo(
            new ConsoleOutput('php://memory'),
            new ConsoleOutput('php://memory')
        );
    }

    private function insertJobWithCreated(string $status, string $created): int
    {
        $conn = ConnectionManager::get('default');
        $conn->insert('queued_jobs', [
            'queue'        => 'default',
            'job_task'     => 'TestTask',
            'data'         => '{}',
            'status'       => $status,
            'failed'       => 0,
            'max_attempts' => 5,
            'priority'     => 5,
            'created'      => $created,
        ]);

        return (int) $conn->lastInsertId();
    }

    private function fetchJob(int $id): ?array
    {
        $row = ConnectionManager::get('default')
            ->execute('SELECT id FROM queued_jobs WHERE id = ?', [$id])
            ->fetch('assoc');

        return $row ?: null;
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
