<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Worker;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Test\Stub\ExplicitRetryTask;
use SpawnQueue\Test\Stub\FailHandler;
use SpawnQueue\Test\Stub\NonRetryableTask;
use SpawnQueue\Test\Stub\RetryHandler;
use SpawnQueue\Test\Stub\RetryableTask;
use SpawnQueue\Test\Stub\SuccessTask;
use SpawnQueue\Test\Stub\NewStyleHandler;
use SpawnQueue\Test\Stub\UnknownErrorTask;
use SpawnQueue\Worker\JobRunner;

class JobRunnerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured.');
        }

        Configure::write('SpawnQueue.connection', 'test');
        $this->ensureTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }

        Configure::delete('SpawnQueue.connection');
    }

    // ── New-style handlers ────────────────────────────────────────────────────

    public function testNewHandlerReturningSuccessMarksJobAsDone(): void
    {
        $id = $this->insertJob(NewStyleHandler::class);

        $exitCode = (new JobRunner())->run($id);

        $row = $this->fetchJob($id);
        $this->assertSame(0, $exitCode);
        $this->assertSame('done', $row['status']);
        $this->assertNotNull($row['completed']);
    }

    public function testNewHandlerReturningRetryMarksJobAsRetryWait(): void
    {
        $id = $this->insertJob(RetryHandler::class, attempts: 1);

        (new JobRunner())->run($id);

        $row = $this->fetchJob($id);
        $this->assertSame('retry_wait', $row['status']);
        $this->assertNotNull($row['notbefore']);
    }

    public function testNewHandlerReturningFailMarksJobAsFailed(): void
    {
        $id = $this->insertJob(FailHandler::class);

        (new JobRunner())->run($id);

        $row = $this->fetchJob($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('permanent error', $row['failure_message']);
    }

    // ── Exception mapping ─────────────────────────────────────────────────────

    public function testRetryableExceptionCalculatesBackoffNotbefore(): void
    {
        // attempt=1 → BACKOFF[1] = 10 s
        $id = $this->insertJob(RetryableTask::class, attempts: 1);

        $before = time();
        (new JobRunner())->run($id);
        $after = time();

        $row = $this->fetchJob($id);
        $this->assertSame('retry_wait', $row['status']);

        $notbeforeTs = strtotime($row['notbefore']);
        $this->assertGreaterThanOrEqual($before + 10, $notbeforeTs);
        $this->assertLessThanOrEqual($after + 10, $notbeforeTs);
    }

    public function testRetryableExceptionWithExplicitRetryAfterSeconds(): void
    {
        $id = $this->insertJob(ExplicitRetryTask::class, attempts: 1);

        $before = time();
        (new JobRunner())->run($id);
        $after = time();

        $row = $this->fetchJob($id);
        $this->assertSame('retry_wait', $row['status']);

        $notbeforeTs = strtotime($row['notbefore']);
        $this->assertGreaterThanOrEqual($before + 300, $notbeforeTs);
        $this->assertLessThanOrEqual($after + 300, $notbeforeTs);
    }

    public function testNonRetryableExceptionDoesNotRetry(): void
    {
        $id = $this->insertJob(NonRetryableTask::class);

        (new JobRunner())->run($id);

        $row = $this->fetchJob($id);
        $this->assertSame('failed', $row['status']);
        $this->assertNull($row['notbefore']);
    }

    public function testUnknownThrowableIsRetryable(): void
    {
        $id = $this->insertJob(UnknownErrorTask::class, attempts: 1);

        (new JobRunner())->run($id);

        $this->assertSame('retry_wait', $this->fetchJob($id)['status']);
    }

    // ── Attempt exhaustion ────────────────────────────────────────────────────

    public function testJobExhaustingMaxAttemptsBecomesDead(): void
    {
        $id = $this->insertJob(RetryableTask::class, attempts: 5, maxAttempts: 5);

        (new JobRunner())->run($id);

        $this->assertSame('dead', $this->fetchJob($id)['status']);
    }

    // ── Guard clauses ─────────────────────────────────────────────────────────

    public function testNonExistentJobReturnsFailCode(): void
    {
        $exitCode = (new JobRunner())->run(999999);

        $this->assertSame(1, $exitCode);
    }

    public function testJobNotInProcessingIsNotExecuted(): void
    {
        $id = $this->insertJob(NewStyleHandler::class, status: 'pending');

        $exitCode = (new JobRunner())->run($id);

        $this->assertSame(1, $exitCode);
        $this->assertSame('pending', $this->fetchJob($id)['status']);
    }

    // ── Legacy handler ────────────────────────────────────────────────────────

    public function testLegacyHandlerResolutionViaLegacyTaskAdapter(): void
    {
        $id = $this->insertJob(SuccessTask::class);

        (new JobRunner())->run($id);

        $this->assertSame('done', $this->fetchJob($id)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertJob(
        string $task,
        string $status = 'processing',
        int $attempts = 0,
        int $maxAttempts = 5
    ): int {
        $conn = ConnectionManager::get('test');
        $conn->insert('queued_jobs', [
            'queue'        => 'default',
            'job_task'     => $task,
            'data'         => '{}',
            'status'       => $status,
            'failed'       => $attempts,
            'max_attempts' => $maxAttempts,
            'priority'     => 5,
            'created'      => date('Y-m-d H:i:s'),
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
