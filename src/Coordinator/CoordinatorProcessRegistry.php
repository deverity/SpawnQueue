<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use SpawnQueue\Utility\ProcessChecker;

/**
 * Persists coordinator process heartbeats in queue_processes.
 *
 * This table gives the queue runtime an explicit control plane separate from
 * queued_jobs. It allows maintenance code to answer questions such as:
 *   - Is the coordinator that claimed this job still alive?
 *   - Has this worker stopped heartbeating for too long?
 *   - Did the process stop cleanly or simply disappear?
 */
class CoordinatorProcessRegistry
{
    private readonly string $resolvedConnectionName;

    public function __construct(string $connectionName = '')
    {
        $this->resolvedConnectionName = $connectionName !== ''
            ? $connectionName
            : (Configure::read('SpawnQueue.connection') ?? 'default');
    }

    public function register(string $workerId, string $queue): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $now = date('Y-m-d H:i:s');
        $host = gethostname() ?: 'unknown';
        $pid = getmypid() ?: null;

        $conn->execute(
            "INSERT INTO queue_processes
                (worker_id, queue, host, pid, status, started_at, heartbeat_at, stopped_at, created, modified)
             VALUES
                (?, ?, ?, ?, 'running', ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE
                queue = VALUES(queue),
                host = VALUES(host),
                pid = VALUES(pid),
                status = 'running',
                started_at = VALUES(started_at),
                heartbeat_at = VALUES(heartbeat_at),
                stopped_at = NULL,
                modified = VALUES(modified)",
            [$workerId, $queue, $host, $pid, $now, $now, $now, $now]
        );
    }

    public function heartbeat(string $workerId): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $now = date('Y-m-d H:i:s');

        $conn->execute(
            "UPDATE queue_processes
             SET status = 'running', heartbeat_at = ?, modified = ?, stopped_at = NULL
             WHERE worker_id = ?",
            [$now, $now, $workerId]
        );
    }

    public function stop(string $workerId): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $now = date('Y-m-d H:i:s');

        $conn->execute(
            "UPDATE queue_processes
             SET status = 'stopped', heartbeat_at = ?, stopped_at = ?, modified = ?
             WHERE worker_id = ?",
            [$now, $now, $now, $workerId]
        );
    }

    /**
     * Remove coordinator registry rows that are no longer useful.
     *
     * Cleanup policy:
     *   - status=stopped rows can be deleted immediately
     *   - status=running rows are deleted only when they are stale and no longer alive
     */
    public function pruneDeadProcesses(?string $queue, int $staleTimeoutSeconds): int
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);

        $sql = "SELECT worker_id, queue, host, pid, status, heartbeat_at, modified
                FROM queue_processes";
        $params = [];

        if ($queue !== null && $queue !== '' && $queue !== '*') {
            $sql .= ' WHERE queue = ?';
            $params[] = $queue;
        }

        $rows = $conn->execute($sql, $params)->fetchAll('assoc');
        if (empty($rows)) {
            return 0;
        }

        $deleted = 0;

        foreach ($rows as $row) {
            $workerId = (string) ($row['worker_id'] ?? '');
            if ($workerId === '') {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === 'stopped') {
                $deleted += $this->deleteWorker($workerId);
                continue;
            }

            if ($status !== 'running') {
                continue;
            }

            if ($this->isRowAlive($row, $staleTimeoutSeconds)) {
                continue;
            }

            $deleted += $this->deleteWorker($workerId);
        }

        return $deleted;
    }

    public function isWorkerAlive(string $workerId, int $staleTimeoutSeconds): bool
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);

        $row = $conn->execute(
            "SELECT host, pid, status, heartbeat_at, modified
             FROM queue_processes
             WHERE worker_id = ?",
            [$workerId]
        )->fetch('assoc');

        if (!$row) {
            return false;
        }

        return $this->isRowAlive($row, $staleTimeoutSeconds);
    }

    private function isLocalHost(string $host): bool
    {
        $localHosts = array_filter([
            gethostname() ?: null,
            php_uname('n') ?: null,
            'localhost',
            '127.0.0.1',
        ]);

        return in_array($host, $localHosts, true);
    }

    private function deleteWorker(string $workerId): int
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);

        return $conn->execute(
            'DELETE FROM queue_processes WHERE worker_id = ?',
            [$workerId]
        )->rowCount();
    }

    private function isRowAlive(array $row, int $staleTimeoutSeconds): bool
    {
        if (($row['status'] ?? null) !== 'running') {
            return false;
        }

        $lastSeenAt = $row['heartbeat_at'] ?? $row['modified'] ?? null;
        if (!$lastSeenAt) {
            return false;
        }

        $threshold = time() - $staleTimeoutSeconds;
        $lastSeenTs = strtotime((string) $lastSeenAt);
        if ($lastSeenTs === false || $lastSeenTs < $threshold) {
            return false;
        }

        $pid = isset($row['pid']) && $row['pid'] !== null ? (int) $row['pid'] : null;
        $host = (string) ($row['host'] ?? '');

        if ($pid !== null && $pid > 0 && $this->isLocalHost($host)) {
            return ProcessChecker::isAlive($pid);
        }

        return true;
    }
}
