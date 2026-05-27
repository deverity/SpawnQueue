<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueStatsCommand;

class QueueStatsCommandIntegrationTest extends TestCase
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

    // ── Counts by queue and status ────────────────────────────────────────────

    public function testCountsByQueueAndStatus(): void
    {
        $this->insertJob('emails', 'pending');
        $this->insertJob('emails', 'pending');
        $this->insertJob('default', 'done');

        [$io, $outHandle] = $this->makeCapturingIo();
        $code = (new QueueStatsCommand())->execute(
            new Arguments([], ['queue' => null], []),
            $io
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);

        rewind($outHandle);
        $output = stream_get_contents($outHandle);
        $this->assertStringContainsString('emails', $output);
        $this->assertStringContainsString('pending', $output);
    }

    // ── Queue filter ──────────────────────────────────────────────────────────

    public function testFilterByQueue(): void
    {
        $this->insertJob('emails', 'pending');
        $this->insertJob('imports', 'pending');

        [$io, $outHandle] = $this->makeCapturingIo();
        $code = (new QueueStatsCommand())->execute(
            new Arguments([], ['queue' => 'emails'], []),
            $io
        );

        $this->assertSame(Command::CODE_SUCCESS, $code);

        rewind($outHandle);
        $output = stream_get_contents($outHandle);
        $this->assertStringContainsString('emails', $output);
        $this->assertStringNotContainsString('imports', $output);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCapturingIo(): array
    {
        $handle = fopen('php://memory', 'w+b');
        $out    = new ConsoleOutput($handle);
        $io     = new ConsoleIo($out, new ConsoleOutput('php://memory'));

        return [$io, $handle];
    }

    private function insertJob(string $queue, string $status): void
    {
        ConnectionManager::get('default')->insert('queued_jobs', [
            'queue'        => $queue,
            'job_task'     => 'TestTask',
            'data'         => '{}',
            'status'       => $status,
            'failed'       => 0,
            'max_attempts' => 5,
            'priority'     => 5,
            'created'      => date('Y-m-d H:i:s'),
        ]);
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
