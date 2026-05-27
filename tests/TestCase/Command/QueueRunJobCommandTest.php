<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueRunJobCommand;

class QueueRunJobCommandTest extends TestCase
{
    protected function setUp(): void
    {
        Configure::write('SpawnQueue.connection', 'test');

        if (ConnectionManager::getConfig('test')) {
            $this->ensureTable();
            $this->truncate();
        }
    }

    protected function tearDown(): void
    {
        Configure::delete('SpawnQueue.connection');

        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }
    }

    // ── Missing job-id (unit, no DB) ──────────────────────────────────────────

    public function testMissingJobIdReturnsError(): void
    {
        $args = new Arguments(
            [],
            ['force' => false, 'worker-id' => null, 'job-id' => null],
            ['job-id']
        );

        $code = (new QueueRunJobCommand())->execute($args, $this->makeIo());

        $this->assertSame(Command::CODE_ERROR, $code);
    }

    // ── Valid job-id runs the job (integration) ───────────────────────────────

    public function testValidJobIdRunsJobAndReturnsSuccess(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured. Set DB_TEST_DSN to enable.');
        }

        $id = $this->insertProcessingJob();

        $args = new Arguments(
            [(string) $id],
            ['force' => true, 'worker-id' => null, 'job-id' => null],
            ['job-id']
        );

        $code = (new QueueRunJobCommand())->execute($args, $this->makeIo());

        $this->assertSame(Command::CODE_SUCCESS, $code);
        $this->assertSame('done', $this->fetchJob($id)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIo(): ConsoleIo
    {
        return new ConsoleIo(
            new ConsoleOutput('php://memory'),
            new ConsoleOutput('php://memory')
        );
    }

    private function insertProcessingJob(): int
    {
        $conn = ConnectionManager::get('test');
        $now  = date('Y-m-d H:i:s');

        $conn->insert('queued_jobs', [
            'queue'        => 'emails',
            'job_task'     => 'SpawnQueue\\Test\\Stub\\NewStyleHandler',
            'data'         => '{}',
            'status'       => 'processing',
            'failed'       => 1,
            'max_attempts' => 5,
            'priority'     => 5,
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

    private function truncate(): void
    {
        ConnectionManager::get('test')->execute('DELETE FROM queued_jobs');
    }
}
