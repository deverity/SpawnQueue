<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;

/**
 * Re-queues failed jobs when the coordinator that marked them as failed
 * is no longer alive and the job still has attempts remaining.
 *
 * This is intentionally conservative:
 *   - only status=failed is considered
 *   - only jobs with failed < max_attempts are eligible
 *   - only jobs whose owner workerkey is stale or missing are revived
 *
 * Revived jobs go to retry_wait with notbefore=now so they can be claimed
 * by the current coordinator on the next scheduling tick.
 */
class FailedJobReviver
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

    public function revive(?string $queue, int $processStaleTimeoutSeconds = 120): int
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $now = date('Y-m-d H:i:s');

        [$queueSql, $queueParams] = $this->buildQueueCondition($queue);

        $failedJobs = $conn->execute(
            "SELECT id, failed, max_attempts, workerkey
             FROM queued_jobs
             WHERE status = 'failed'
               AND failed < max_attempts
               AND {$queueSql}",
            $queueParams
        )->fetchAll('assoc');

        if (empty($failedJobs)) {
            return 0;
        }

        $revived = 0;

        foreach ($failedJobs as $row) {
            $jobId = (int) $row['id'];
            $workerKey = $row['workerkey'] ?? null;

            // If the worker that produced the failed status is still alive,
            // do not second-guess the failure classification.
            if (is_string($workerKey) && $workerKey !== '' && $this->processRegistry->isWorkerAlive($workerKey, $processStaleTimeoutSeconds)) {
                continue;
            }

            $updated = $conn->execute(
                "UPDATE queued_jobs
                 SET status = 'retry_wait',
                     notbefore = ?,
                     fetched = NULL,
                     pid = NULL,
                     failed_at = NULL,
                     failure_message = CONCAT(COALESCE(failure_message, ''), ?)
                 WHERE id = ?
                   AND status = 'failed'
                   AND failed < max_attempts",
                [$now, ' [revived by coordinator after owner disappeared]', $jobId]
            )->rowCount();

            if ($updated > 0) {
                $revived++;
            }
        }

        return $revived;
    }

    private function buildQueueCondition(?string $queue): array
    {
        if ($queue === null || $queue === '' || $queue === '*') {
            return ['1 = 1', []];
        }

        if ($queue === 'default') {
            return ["(queue = ? OR queue IS NULL)", [$queue]];
        }

        return ["queue = ?", [$queue]];
    }
}
