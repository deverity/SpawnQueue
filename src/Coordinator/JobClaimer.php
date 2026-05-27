<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use SpawnQueue\ValueObject\JobData;

/**
 * Atomically claims a single eligible job from the queue.
 *
 * Claim strategy:
 *   1. BEGIN transaction
 *   2. SELECT … FOR UPDATE SKIP LOCKED  (avoids contention between concurrent coordinators)
 *   3. UPDATE status → 'processing', set fetched, workerkey, failed+1
 *   4. COMMIT
 *   5. Return full row as JobData
 *
 * Eligibility criteria:
 *   • Status is 'pending' or 'retry_wait'
 *   • OR (legacy dereuromark job) status IS NULL AND fetched IS NULL AND completed IS NULL
 *   • notbefore IS NULL OR notbefore <= NOW()
 *   • Queue matches the coordinator's queue
 *     (coordinators for queue 'default' also pick up jobs with queue IS NULL)
 *
 * If SELECT … SKIP LOCKED is unavailable the claim falls back to a
 * conditional UPDATE, which is safe but may cause contention on busy queues.
 */
class JobClaimer
{
    /** Cached after the first call; null means "not yet detected". */
    private ?bool $skipLockedSupported = null;

    public function __construct(
        private readonly string $connectionName = ''
    ) {}

    public function claim(string $queue, string $workerId): ?JobData
    {
        if ($this->skipLockedSupported === null) {
            $this->skipLockedSupported = $this->detectSkipLockedSupport();
        }

        if ($this->skipLockedSupported) {
            return $this->claimWithSkipLocked($queue, $workerId);
        }

        return $this->claimWithConditionalUpdate($queue, $workerId);
    }

    /**
     * Probes the database once to determine whether FOR UPDATE SKIP LOCKED
     * is supported. The result is cached for the lifetime of this object.
     * Any real connection error here will surface on the first real claim.
     */
    private function detectSkipLockedSupport(): bool
    {
        try {
            $conn = ConnectionManager::get($this->resolvedConnectionName());
            $conn->execute('SELECT 1 FROM queued_jobs WHERE 1=0 FOR UPDATE SKIP LOCKED');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolvedConnectionName(): string
    {
        return $this->connectionName !== ''
            ? $this->connectionName
            : (Configure::read('SpawnQueue.connection') ?? 'default');
    }

    // -------------------------------------------------------------------------

    private function claimWithSkipLocked(string $queue, string $workerId): ?JobData
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName());
        $now  = date('Y-m-d H:i:s');

        [$queueSql, $queueParams] = $this->buildQueueCondition($queue);

        $conn->begin();
        try {
            $row = $conn->execute(
                "SELECT id FROM queued_jobs
                 WHERE {$queueSql}
                   AND (
                         status IN ('pending', 'retry_wait')
                      OR (status IS NULL AND fetched IS NULL AND completed IS NULL)
                   )
                   AND (notbefore IS NULL OR notbefore <= ?)
                 ORDER BY priority DESC, notbefore ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED",
                [...$queueParams, $now]
            )->fetch('assoc');

            if (!$row) {
                $conn->rollback();
                return null;
            }

            $jobId = (int) $row['id'];

            $affected = $conn->execute(
                "UPDATE queued_jobs
                 SET status    = 'processing',
                     fetched   = ?,
                     workerkey = ?,
                     failed    = failed + 1
                 WHERE id = ?
                   AND (
                         status IN ('pending', 'retry_wait')
                      OR (status IS NULL AND fetched IS NULL)
                   )",
                [$now, $workerId, $jobId]
            )->rowCount();

            if ($affected === 0) {
                $conn->rollback();
                return null;
            }

            $conn->commit();

            return $this->fetchFullRow($jobId);
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /**
     * Fallback for databases that do not support SKIP LOCKED.
     * Uses a conditional UPDATE to atomically claim the job.
     */
    private function claimWithConditionalUpdate(string $queue, string $workerId): ?JobData
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName());
        $now  = date('Y-m-d H:i:s');

        [$queueSql, $queueParams] = $this->buildQueueCondition($queue);

        // Find a candidate id.
        $row = $conn->execute(
            "SELECT id FROM queued_jobs
             WHERE {$queueSql}
               AND (
                     status IN ('pending', 'retry_wait')
                  OR (status IS NULL AND fetched IS NULL AND completed IS NULL)
               )
               AND (notbefore IS NULL OR notbefore <= ?)
             ORDER BY priority DESC, notbefore ASC, id ASC
             LIMIT 1",
            [...$queueParams, $now]
        )->fetch('assoc');

        if (!$row) {
            return null;
        }

        $jobId = (int) $row['id'];

        // Claim with a conditional update (race-safe).
        $affected = $conn->execute(
            "UPDATE queued_jobs
             SET status    = 'processing',
                 fetched   = ?,
                 workerkey = ?,
                 failed    = failed + 1
             WHERE id = ?
               AND (
                     status IN ('pending', 'retry_wait')
                  OR (status IS NULL AND fetched IS NULL)
               )",
            [$now, $workerId, $jobId]
        )->rowCount();

        if ($affected === 0) {
            return null; // Another process claimed it first
        }

        return $this->fetchFullRow($jobId);
    }

    /** Returns the SQL fragment and bound parameters for the queue filter. */
    private function buildQueueCondition(string $queue): array
    {
        if ($queue === 'default') {
            return ["(queue = ? OR queue IS NULL)", [$queue]];
        }

        return ["queue = ?", [$queue]];
    }

    private function fetchFullRow(int $jobId): ?JobData
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName());
        $row  = $conn->execute('SELECT * FROM queued_jobs WHERE id = ?', [$jobId])->fetch('assoc');

        return $row ? JobData::fromRow($row) : null;
    }

    /**
     * Returns the next $limit pending (or retry_wait) jobs without claiming them.
     * Used to populate the TUI "pending" panel on each coordinator heartbeat.
     *
     * @return list<array{jobId:int, taskName:string, createdAt:string, maxAttempts:int}>
     */
    public function peekPending(string $queue, int $limit = 5): array
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName());
        $now  = date('Y-m-d H:i:s');

        [$queueSql, $queueParams] = $this->buildQueueCondition($queue);

        $rows = $conn->execute(
            "SELECT id, job_task, created, max_attempts
             FROM queued_jobs
             WHERE {$queueSql}
               AND (
                     status IN ('pending', 'retry_wait')
                  OR (status IS NULL AND fetched IS NULL AND completed IS NULL)
               )
               AND (notbefore IS NULL OR notbefore <= ?)
             ORDER BY priority DESC, notbefore ASC, id ASC
             LIMIT {$limit}",
            [...$queueParams, $now]
        )->fetchAll('assoc');

        return array_map(fn(array $row) => [
            'jobId'       => (int)    $row['id'],
            'taskName'    => (string) ($row['job_task']     ?? ''),
            'createdAt'   => (string) ($row['created']      ?? ''),
            'maxAttempts' => (int)    ($row['max_attempts']  ?? 1),
        ], $rows ?: []);
    }

    /**
     * Releases a claim that could not be handed off to a child process.
     * Sets the job back to 'pending' so it can be re-claimed.
     */
    public function release(int $jobId): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName());
        $conn->execute(
            "UPDATE queued_jobs
             SET status = 'pending', fetched = NULL, workerkey = NULL,
                 failed = GREATEST(failed - 1, 0), pid = NULL
             WHERE id = ? AND status = 'processing'",
            [$jobId]
        );
    }
}
