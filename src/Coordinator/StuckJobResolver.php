<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use SpawnQueue\Utility\ProcessChecker;
use SpawnQueue\ValueObject\BackoffSchedule;

/**
 * Detects and recovers jobs that are stuck in processing state.
 *
 * A job is considered recoverable when:
 *   - status = processing
 *   - fetched/reserved timestamp is older than the configured threshold
 *   - the recorded PID is missing or no longer alive
 *
 * This avoids recycling a legitimate long-running child that is still active.
 */
class StuckJobResolver
{
    private CoordinatorProcessRegistry $processRegistry;

    private readonly string $resolvedConnectionName;

    public function __construct(string $connectionName = '')
    {
        $this->resolvedConnectionName = $connectionName !== ''
            ? $connectionName
            : (Configure::read('SpawnQueue.connection') ?? 'default');
        $this->processRegistry = new CoordinatorProcessRegistry($this->resolvedConnectionName);
    }

    public function resolve(?string $queue, int $stuckTimeoutSeconds, int $processStaleTimeoutSeconds = 120): int
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $threshold = date('Y-m-d H:i:s', time() - $stuckTimeoutSeconds);

        [$queueSql, $queueParams] = $this->buildQueueCondition($queue);

        $stuck = $conn->execute(
            "SELECT id, failed, max_attempts, pid, workerkey
             FROM queued_jobs
             WHERE status = 'processing'
               AND fetched <= ?
               AND {$queueSql}",
            [$threshold, ...$queueParams]
        )->fetchAll('assoc');

        if (empty($stuck)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $recovered = 0;

        foreach ($stuck as $row) {
            $jobId = (int) $row['id'];
            $attempts = (int) $row['failed'];
            $maxAttempts = (int) $row['max_attempts'];
            $pid = isset($row['pid']) && $row['pid'] !== null ? (int) $row['pid'] : null;
            $workerKey = $row['workerkey'] ?? null;

            // Ignore jobs whose child or owning coordinator still appears alive.
            if (!$this->isJobAbandoned($pid, is_string($workerKey) ? $workerKey : null, $processStaleTimeoutSeconds)) {
                continue;
            }

            if ($attempts >= $maxAttempts) {
                $updated = $conn->execute(
                    "UPDATE queued_jobs
                     SET status = 'dead', failure_message = ?, failed_at = ?, pid = NULL
                     WHERE id = ? AND status = 'processing'",
                    ["Stuck job exhausted all {$maxAttempts} attempts.", $now, $jobId]
                )->rowCount();
            } else {
                $retryUntil = date('Y-m-d H:i:s', time() + BackoffSchedule::delayFor($attempts));
                $updated = $conn->execute(
                    "UPDATE queued_jobs
                     SET status = 'retry_wait', notbefore = ?, fetched = NULL, pid = NULL,
                         failure_message = 'Job was stuck in processing and was re-queued by coordinator.'
                     WHERE id = ? AND status = 'processing'",
                    [$retryUntil, $jobId]
                )->rowCount();
            }

            if ($updated > 0) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function buildQueueCondition(?string $queue): array
    {
        // Null or "*" means the maintenance command wants to scan all queues.
        if ($queue === null || $queue === '' || $queue === '*') {
            return ['1 = 1', []];
        }

        if ($queue === 'default') {
            return ["(queue = ? OR queue IS NULL)", [$queue]];
        }

        return ["queue = ?", [$queue]];
    }

    private function isJobAbandoned(?int $pid, ?string $workerKey, int $processStaleTimeoutSeconds): bool
    {
        if ($pid !== null && $pid > 0) {
            return !ProcessChecker::isAlive($pid);
        }

        if ($workerKey !== null && $workerKey !== '') {
            return !$this->processRegistry->isWorkerAlive($workerKey, $processStaleTimeoutSeconds);
        }

        return true;
    }
}
