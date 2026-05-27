<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\ChildProcess;
use SpawnQueue\Coordinator\ChildProcessManager;
use SpawnQueue\ValueObject\JobData;

class ChildProcessManagerIntegrationTest extends TestCase
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

    // ── Spawn ─────────────────────────────────────────────────────────────────

    public function testSpawnCreatesChildProcess(): void
    {
        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(30, 'test-worker', 'default', 'test');

        $manager->spawn($this->makeJobData($jobId));

        $this->assertSame(1, $manager->count());

        $this->drainManager($manager);
    }

    public function testSpawnWritesPidToJob(): void
    {
        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(30, 'test-worker', 'default', 'test');

        $manager->spawn($this->makeJobData($jobId));

        $row = $this->fetchJob($jobId);
        $this->assertNotNull($row['pid']);
        $this->assertGreaterThan(0, (int) $row['pid']);

        $this->drainManager($manager);
    }

    // ── Reap ──────────────────────────────────────────────────────────────────

    public function testReapRemovesFinishedProcessWithExitCodeZero(): void
    {
        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(30, 'test-worker', 'default', 'test');

        [$resource, $pipes] = $this->openProcess('exit(0);');
        $this->injectProcess($manager, $jobId, new ChildProcess(
            jobId:     $jobId,
            resource:  $resource,
            pipes:     $pipes,
            startedAt: time(),
            timeoutAt: time() + 30,
        ));

        $this->assertSame(1, $manager->count());
        $this->waitForExit($resource);
        $manager->reap();

        $this->assertSame(0, $manager->count());
    }

    public function testReapWithNonZeroExitCodeRequeuesJob(): void
    {
        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(30, 'test-worker', 'default', 'test');

        [$resource, $pipes] = $this->openProcess('exit(1);');
        $this->injectProcess($manager, $jobId, new ChildProcess(
            jobId:     $jobId,
            resource:  $resource,
            pipes:     $pipes,
            startedAt: time(),
            timeoutAt: time() + 30,
        ));

        $this->waitForExit($resource);
        $manager->reap();

        $this->assertSame('retry_wait', $this->fetchJob($jobId)['status']);
    }

    // ── Timeout / kill ────────────────────────────────────────────────────────

    /** @group posix */
    public function testTimeoutSendsSigterm(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('SIGTERM requires a POSIX environment.');
        }

        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(1, 'test-worker', 'default', 'test');

        [$resource, $pipes] = $this->openProcess('sleep(60);');
        $this->injectProcess($manager, $jobId, new ChildProcess(
            jobId:     $jobId,
            resource:  $resource,
            pipes:     $pipes,
            startedAt: time() - 10,
            timeoutAt: time() - 1,
        ));

        $manager->reap(); // enforces timeout → sends SIGTERM

        $this->waitForExit($resource, 3000);
        $manager->reap();

        $this->assertSame(0, $manager->count());
    }

    /** @group posix */
    public function testKillForcedAfterGracePeriod(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('SIGKILL requires a POSIX environment.');
        }

        $jobId = $this->insertProcessingJob();
        $manager = new ChildProcessManager(1, 'test-worker', 'default', 'test');

        [$resource, $pipes] = $this->openProcess('sleep(60);');
        $this->injectProcess($manager, $jobId, new ChildProcess(
            jobId:         $jobId,
            resource:      $resource,
            pipes:         $pipes,
            startedAt:     time() - 30,
            timeoutAt:     time() - 10,
            sigtermSent:   true,
            sigtermSentAt: time() - 10,
        ));

        $manager->reap(); // grace expired → sends SIGKILL

        $this->waitForExit($resource, 2000);
        $manager->reap();

        $this->assertSame(0, $manager->count());
    }

    // ── Exhausted attempts ────────────────────────────────────────────────────

    public function testJobWithExhaustedAttemptsBecomesDeadOnForcedKill(): void
    {
        $jobId = $this->insertProcessingJob(attempts: 5, maxAttempts: 5);
        $manager = new ChildProcessManager(30, 'test-worker', 'default', 'test');

        [$resource, $pipes] = $this->openProcess('sleep(60);');
        $this->injectProcess($manager, $jobId, new ChildProcess(
            jobId:     $jobId,
            resource:  $resource,
            pipes:     $pipes,
            startedAt: time(),
            timeoutAt: time() + 30,
        ));

        $manager->killAll();

        $this->assertSame('dead', $this->fetchJob($jobId)['status']);
        $this->assertSame(0, $manager->count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function injectProcess(ChildProcessManager $manager, int $jobId, ChildProcess $child): void
    {
        $prop = new \ReflectionProperty($manager, 'processes');
        $prop->setAccessible(true);
        $current = $prop->getValue($manager);
        $current[$jobId] = $child;
        $prop->setValue($manager, $current);
    }

    private function openProcess(string $phpCode): array
    {
        $cmd  = PHP_BINARY . ' -r ' . escapeshellarg($phpCode);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $resource = proc_open($cmd, $desc, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$resource, $pipes];
    }

    private function waitForExit(mixed $resource, int $maxMs = 3000): void
    {
        $deadline = microtime(true) + $maxMs / 1000.0;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($resource)['running']) {
                return;
            }
            usleep(10_000);
        }
    }

    private function drainManager(ChildProcessManager $manager, int $maxMs = 3000): void
    {
        $deadline = microtime(true) + $maxMs / 1000.0;
        while ($manager->count() > 0 && microtime(true) < $deadline) {
            $manager->reap();
            usleep(50_000);
        }
        if ($manager->count() > 0) {
            $manager->killAll();
        }
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
            'workerkey'       => null,
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ]);
    }

    private function insertProcessingJob(int $attempts = 0, int $maxAttempts = 5): int
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('queued_jobs', [
            'queue'        => 'default',
            'job_task'     => 'TestTask',
            'data'         => '{}',
            'status'       => 'processing',
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
