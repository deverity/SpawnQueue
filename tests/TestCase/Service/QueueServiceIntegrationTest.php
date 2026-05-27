<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Service;

use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Service\QueueService;
use SpawnQueue\Test\Stub\NewStyleHandler;
use SpawnQueue\Test\Stub\UndefinedQueueHandler;

class QueueServiceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured.');
        }

        $this->ensureTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }
    }

    // ── Basic push ────────────────────────────────────────────────────────────

    public function testPushReturnsInsertedId(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, ['to' => 'a@b.com'], connection: 'test');

        $this->assertGreaterThan(0, $id);
    }

    public function testPushWritesCorrectQueue(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('emails', $row['queue']);
    }

    public function testPushNewStyleHandlerUsesHandlerQueue(): void
    {
        $id = QueueService::push(NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('emails', $row['queue']);
    }

    public function testPushLegacyTaskWithoutQueueUsesDefaultQueue(): void
    {
        $id = QueueService::push('App\Queue\Task\MyLegacyTask', ['foo' => 'bar'], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('default', $row['queue']);
        $this->assertSame('App\Queue\Task\MyLegacyTask', $row['job_task']);
    }

    public function testPushUndefinedQueueUsesDefaultQueue(): void
    {
        $id = QueueService::push('undefined', 'App\Queue\Task\MyLegacyTask', [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('default', $row['queue']);
    }

    public function testPushWritesCorrectTask(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame(NewStyleHandler::class, $row['job_task']);
    }

    public function testPushWritesJsonPayload(): void
    {
        $payload = ['to' => 'user@example.com', 'subject' => 'Hello'];
        $id      = QueueService::push('emails', NewStyleHandler::class, $payload, connection: 'test');

        $row     = $this->fetchJob($id);
        $decoded = json_decode($row['data'], true);
        $this->assertSame($payload, $decoded);
    }

    public function testPushSetsStatusToPending(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('pending', $row['status']);
    }

    public function testPushSetsDefaultPriority(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame(5, (int) $row['priority']);
    }

    public function testPushSetsAttemptsToZero(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], connection: 'test');

        $row = $this->fetchJob($id);
        $this->assertSame(0, (int) $row['failed']);
    }

    // ── Options ───────────────────────────────────────────────────────────────

    public function testPushWithCustomPriority(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], ['priority' => 9], connection: 'test');

        $this->assertSame(9, (int) $this->fetchJob($id)['priority']);
    }

    public function testPushWithCustomMaxAttempts(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], ['max_attempts' => 2], connection: 'test');

        $this->assertSame(2, (int) $this->fetchJob($id)['max_attempts']);
    }

    public function testPushWithDelay(): void
    {
        $before = time() + 55; // small buffer for test duration
        $id     = QueueService::push('emails', NewStyleHandler::class, [], ['delay' => 60], connection: 'test');
        $after  = time() + 65;

        $row         = $this->fetchJob($id);
        $notbeforeTs = strtotime($row['notbefore']);

        $this->assertGreaterThanOrEqual($before, $notbeforeTs);
        $this->assertLessThanOrEqual($after, $notbeforeTs);
    }

    public function testPushWithAbsoluteAvailableAt(): void
    {
        $at = '2030-01-01 08:00:00';
        $id = QueueService::push('emails', NewStyleHandler::class, [], ['available_at' => $at], connection: 'test');

        $this->assertSame($at, $this->fetchJob($id)['notbefore']);
    }

    public function testPushWithReference(): void
    {
        $id = QueueService::push('emails', NewStyleHandler::class, [], ['reference' => 'order-42'], connection: 'test');

        $this->assertSame('order-42', $this->fetchJob($id)['reference']);
    }

    // ── pushAt ────────────────────────────────────────────────────────────────

    public function testPushAtSetsCorrectNotbefore(): void
    {
        $at = '2030-06-15 12:00:00';
        $id = QueueService::pushAt('emails', NewStyleHandler::class, [], $at, connection: 'test');

        $this->assertSame($at, $this->fetchJob($id)['notbefore']);
    }

    // ── Connection as positional argument ─────────────────────────────────────

    public function testPushNewStyleWithConnectionAsFourthArg(): void
    {
        $id = QueueService::push(NewStyleHandler::class, ['key' => 'val'], [], 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('emails', $row['queue']);
        $this->assertSame(NewStyleHandler::class, $row['job_task']);
    }

    public function testPushLegacyTaskWithConnectionAsFourthArg(): void
    {
        $id = QueueService::push('App\Queue\Task\MyTask', ['a' => 1], [], 'test');

        $row = $this->fetchJob($id);
        $this->assertSame('default', $row['queue']);
        $this->assertSame('App\Queue\Task\MyTask', $row['job_task']);
    }

    public function testPushAtNewStyleWithConnectionAsFifthArg(): void
    {
        $at = '2030-12-31 23:59:59';
        $id = QueueService::pushAt(NewStyleHandler::class, [], $at, [], 'test');

        $row = $this->fetchJob($id);
        $this->assertSame($at, $row['notbefore']);
        $this->assertSame('emails', $row['queue']);
    }

    public function testPushAtExplicitQueueWithConnection(): void
    {
        $at = '2030-12-31 23:59:59';
        $id = QueueService::pushAt('emails', NewStyleHandler::class, [], $at, [], 'test');

        $row = $this->fetchJob($id);
        $this->assertSame($at, $row['notbefore']);
        $this->assertSame('emails', $row['queue']);
    }

    // ── Queue name normalisation ───────────────────────────────────────────────

    public function testPushEmptyQueueFallsToDefault(): void
    {
        $id = QueueService::push('', NewStyleHandler::class, [], connection: 'test');

        $this->assertSame('default', $this->fetchJob($id)['queue']);
    }

    public function testPushHandlerReturningUndefinedQueueFallsToDefault(): void
    {
        $id = QueueService::push(UndefinedQueueHandler::class, [], connection: 'test');

        $this->assertSame('default', $this->fetchJob($id)['queue']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
                failed_at    DATETIME      NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function truncate(): void
    {
        ConnectionManager::get('test')->execute('DELETE FROM queued_jobs');
    }
}
