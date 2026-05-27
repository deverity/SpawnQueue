<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\JobClaimer;

/**
 * Integration tests for JobClaimer.
 *
 * Requires a running MySQL/MariaDB instance.
 * Set DB_TEST_DSN or configure a 'test' connection in tests/bootstrap.php.
 *
 * Run only this suite:
 *   vendor/bin/phpunit --testsuite integration
 */
class JobClaimerIntegrationTest extends TestCase
{
    private JobClaimer $claimer;

    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured. Set DB_TEST_DSN to enable integration tests.');
        }

        $this->claimer = new JobClaimer('test');
        $this->createTable();
        $this->truncateTable();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncateTable();
        }
    }

    // ── Claim a pending job ───────────────────────────────────────────────────

    public function testClaimReturnsPendingJob(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending']);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
    }

    public function testClaimSetsStatusToProcessing(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending']);
        $this->claimer->claim('default', 'worker-1');

        $row = $this->fetchJob($id);
        $this->assertSame('processing', $row['status']);
    }

    public function testClaimSetsWorkerKey(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending']);
        $this->claimer->claim('default', 'worker-test-42');

        $row = $this->fetchJob($id);
        $this->assertSame('worker-test-42', $row['workerkey']);
    }

    public function testClaimIncrementsAttemptCounter(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending', 'failed' => 0]);
        $this->claimer->claim('default', 'worker-1');

        $row = $this->fetchJob($id);
        $this->assertSame(1, (int) $row['failed']);
    }

    public function testClaimSetsFetchedTimestamp(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending']);
        $this->claimer->claim('default', 'worker-1');

        $row = $this->fetchJob($id);
        $this->assertNotNull($row['fetched']);
    }

    // ── Claim retry_wait job ──────────────────────────────────────────────────

    public function testClaimPicksUpRetryWaitJob(): void
    {
        $past = date('Y-m-d H:i:s', time() - 60);
        $id   = $this->insertJob([
            'queue'     => 'default',
            'status'    => 'retry_wait',
            'notbefore' => $past,
        ]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
    }

    public function testClaimSkipsRetryWaitJobNotYetDue(): void
    {
        $future = date('Y-m-d H:i:s', time() + 300);
        $this->insertJob([
            'queue'     => 'default',
            'status'    => 'retry_wait',
            'notbefore' => $future,
        ]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNull($job, 'Job not yet due must not be claimed');
    }

    // ── Legacy dereuromark jobs (null status, null fetched) ───────────────────

    public function testClaimPicksUpLegacyJobWithNullStatus(): void
    {
        $id = $this->insertJob([
            'queue'   => null,
            'status'  => null,
            'fetched' => null,
        ]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNotNull($job, 'Default coordinator must pick up jobs with queue=NULL');
        $this->assertSame($id, $job->id);
    }

    public function testNonDefaultQueueDoesNotPickUpLegacyJobs(): void
    {
        $this->insertJob([
            'queue'  => null,
            'status' => null,
        ]);

        // 'emails' queue should NOT pick up NULL-queue jobs
        $job = $this->claimer->claim('emails', 'worker-1');

        $this->assertNull($job);
    }

    // ── Empty queue ───────────────────────────────────────────────────────────

    public function testClaimReturnsNullWhenQueueIsEmpty(): void
    {
        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNull($job);
    }

    // ── Terminal statuses are not claimed ─────────────────────────────────────

    /** @dataProvider terminalStatusProvider */
    public function testClaimSkipsTerminalStatuses(string $status): void
    {
        $this->insertJob(['queue' => 'default', 'status' => $status]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNull($job, "Status '{$status}' must never be claimed");
    }

    public static function terminalStatusProvider(): array
    {
        return [
            ['done'],
            ['failed'],
            ['dead'],
            ['cancelled'],
            ['processing'],
        ];
    }

    // ── Priority ordering ─────────────────────────────────────────────────────

    public function testHigherPriorityJobIsClaimedFirst(): void
    {
        $lowId  = $this->insertJob(['queue' => 'default', 'status' => 'pending', 'priority' => 3]);
        $highId = $this->insertJob(['queue' => 'default', 'status' => 'pending', 'priority' => 9]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertSame($highId, $job?->id, 'Higher priority must be claimed first');
    }

    // ── Release ───────────────────────────────────────────────────────────────

    public function testReleasePutsJobBackToPending(): void
    {
        $id = $this->insertJob(['queue' => 'default', 'status' => 'pending']);
        $this->claimer->claim('default', 'worker-1');

        $this->claimer->release($id);

        $row = $this->fetchJob($id);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['fetched']);
    }

    public function testReleaseDoesNotAlterNonExistentJob(): void
    {
        $this->claimer->release(999999);
        $this->addToAssertionCount(1); // no exception is the assertion
    }

    // ── Tiebreakers ───────────────────────────────────────────────────────────

    public function testTiebreakByCreatedAscWhenPrioritiesAreEqual(): void
    {
        $earlyId = $this->insertJob(['priority' => 5, 'created' => date('Y-m-d H:i:s', time() - 100)]);
        $this->insertJob(['priority' => 5, 'created' => date('Y-m-d H:i:s')]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertSame($earlyId, $job?->id, 'Older job (lower id) must be claimed first when priority is equal');
    }

    public function testTiebreakByIdAscWhenPriorityAndCreatedAreEqual(): void
    {
        $sameCreated = date('Y-m-d H:i:s');

        $lowId = $this->insertJob(['priority' => 5, 'created' => $sameCreated]);
        $this->insertJob(['priority' => 5, 'created' => $sameCreated]);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertSame($lowId, $job?->id, 'Lower id must win when priority and created are equal');
    }

    // ── PeekPending ───────────────────────────────────────────────────────────

    public function testPeekPendingReturnsOnlyEligibleJobs(): void
    {
        $eligibleId = $this->insertJob(['status' => 'pending']);
        $this->insertJob(['status' => 'done']);
        $this->insertJob(['status' => 'pending', 'notbefore' => date('Y-m-d H:i:s', time() + 300)]);

        $results = $this->claimer->peekPending('default', 10);

        $this->assertCount(1, $results);
        $this->assertSame($eligibleId, $results[0]['jobId']);
    }

    public function testPeekPendingRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertJob(['status' => 'pending']);
        }

        $results = $this->claimer->peekPending('default', 3);

        $this->assertCount(3, $results);
    }

    // ── Conditional update fallback ───────────────────────────────────────────

    public function testClaimWithConditionalUpdateFallback(): void
    {
        $id = $this->insertJob(['status' => 'pending']);

        $prop = new \ReflectionProperty($this->claimer, 'skipLockedSupported');
        $prop->setAccessible(true);
        $prop->setValue($this->claimer, false);

        $job = $this->claimer->claim('default', 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('processing', $this->fetchJob($id)['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertJob(array $fields): int
    {
        $conn = ConnectionManager::get('test');
        $now  = date('Y-m-d H:i:s');

        $conn->insert('queued_jobs', array_merge([
            'queue'        => 'default',
            'job_task'     => 'SpawnQueue\\Test\\Stub\\SuccessTask',
            'data'         => json_encode([]),
            'status'       => 'pending',
            'priority'     => 5,
            'failed'       => 0,
            'max_attempts' => 5,
            'notbefore'    => null,
            'fetched'      => null,
            'completed'    => null,
            'workerkey'    => null,
            'pid'          => null,
            'created'      => $now,
        ], $fields));

        return (int) $conn->lastInsertId();
    }

    private function fetchJob(int $id): array
    {
        return ConnectionManager::get('test')
            ->execute('SELECT * FROM queued_jobs WHERE id = ?', [$id])
            ->fetch('assoc');
    }

    private function createTable(): void
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
    }

    private function truncateTable(): void
    {
        ConnectionManager::get('test')->execute('DELETE FROM queued_jobs');
    }
}
