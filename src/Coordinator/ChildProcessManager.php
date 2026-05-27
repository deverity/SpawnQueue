<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use SpawnQueue\Console\TuiLogger;
use SpawnQueue\ValueObject\BackoffSchedule;
use SpawnQueue\ValueObject\JobData;

/**
 * Manages the lifecycle of child worker processes.
 *
 * Each child runs:
 *   php bin/cake queue:run-job <id> --worker-id=<worker>
 *
 * Responsibilities:
 *   - Spawn a child for a claimed job.
 *   - Monitor running children and flush their output.
 *   - Enforce per-job timeouts (SIGTERM -> SIGKILL).
 *   - Re-queue jobs when a child exits before updating the database.
 */
class ChildProcessManager
{
    /** @var array<int, ChildProcess> keyed by job ID */
    private array $processes = [];

    private readonly string $cakeBin;
    private readonly string $resolvedConnectionName;

    public function __construct(
        private readonly int $timeout,
        private readonly string $workerId,
        private readonly string $queue,
        private readonly int $sigtermGracePeriod = 5,
        private readonly string $connectionName = '',
    ) {
        $this->resolvedConnectionName = $connectionName !== ''
            ? $connectionName
            : (Configure::read('SpawnQueue.connection') ?? 'default');
        $this->cakeBin = $this->resolveCakeBin();
    }

    public function spawn(JobData $job): void
    {
        // Pass both job id and worker ownership so the child validates the claim
        // before it touches business logic.
        $cmd = sprintf(
            '%s %s queue:run-job %s --worker-id=%s',
            escapeshellarg(PHP_BINARY),
            $this->cakeBin,
            escapeshellarg((string) $job->id),
            escapeshellarg($this->workerId),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $resource = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($resource)) {
            throw new \RuntimeException("proc_open failed for job #{$job->id}");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($resource);
        $pid = $status['pid'] ?? null;

        if ($pid) {
            // Persist the child PID so stuck-job recovery can distinguish
            // abandoned work from a still-running process.
            ConnectionManager::get($this->resolvedConnectionName)->execute(
                'UPDATE queued_jobs SET pid = ? WHERE id = ?',
                [$pid, $job->id]
            );
        }

        $this->processes[$job->id] = new ChildProcess(
            jobId: $job->id,
            resource: $resource,
            pipes: $pipes,
            startedAt: time(),
            timeoutAt: time() + $this->timeout,
        );

        $this->log("SPAWNED job_id={$job->id} pid={$pid}");
        TuiLogger::workerSpawned($this->queue, $job->id, $job->task);
    }

    public function reap(): void
    {
        foreach ($this->processes as $jobId => $child) {
            // Flush child output first so logs stay close to the right lifecycle event.
            $this->flushOutput($child);

            $status = proc_get_status($child->resource);
            if (!$status['running']) {
                $this->handleFinished($child, $status['exitcode'] ?? -1);
                unset($this->processes[$jobId]);
                continue;
            }

            $this->enforceTimeout($child);
        }
    }

    public function hasSlot(int $maxWorkers): bool
    {
        return count($this->processes) < $maxWorkers;
    }

    public function count(): int
    {
        return count($this->processes);
    }

    public function terminateAll(): void
    {
        foreach ($this->processes as $child) {
            if ($child->sigtermSent) {
                continue;
            }

            // Graceful shutdown path: ask the child to stop and give it a short window.
            proc_terminate($child->resource, defined('SIGTERM') ? SIGTERM : 15);
            $child->sigtermSent = true;
            $child->sigtermSentAt = time();
        }
    }

    public function killAll(): void
    {
        foreach ($this->processes as $jobId => $child) {
            $this->flushOutput($child);

            // Hard shutdown path: if we kill a child here, it no longer has a chance
            // to update the DB, so the manager must recover the job itself.
            proc_terminate($child->resource, defined('SIGKILL') ? SIGKILL : 9);
            proc_close($child->resource);
            $this->closePipes($child);
            $this->requeueCrashedJob($jobId, -9);
            unset($this->processes[$jobId]);
        }
    }

    private function handleFinished(ChildProcess $child, int $exitCode): void
    {
        proc_close($child->resource);
        $this->closePipes($child);

        $this->log("FINISHED job_id={$child->jobId} exit_code={$exitCode}");

        if ($exitCode !== 0) {
            // Defensive recovery: the child may have crashed before persisting retry/failure.
            $this->requeueCrashedJob($child->jobId, $exitCode);
        }

        // Record before clearing the slot so both updates land in the same redraw.
        $this->recordCompletedTask($child->jobId);
        TuiLogger::workerFinished($this->queue, $child->jobId);
    }

    /**
     * Queries the final job state from the DB and feeds it to the Recent Tasks panel.
     * Called after requeueCrashedJob() (when exitCode != 0) so the status is already final.
     */
    private function recordCompletedTask(int $jobId): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $row  = $conn->execute(
            'SELECT status, failed, max_attempts, job_task, created, fetched, completed, failed_at
             FROM queued_jobs WHERE id = ?',
            [$jobId]
        )->fetch('assoc');

        if (!$row) {
            return;
        }

        // Use completed for done, failed_at for dead/failed, or now() for retry_wait.
        $finishedAt = ($row['completed'] !== null && $row['completed'] !== '')
            ? (string) $row['completed']
            : (($row['failed_at'] !== null && $row['failed_at'] !== '')
                ? (string) $row['failed_at']
                : date('Y-m-d H:i:s'));

        TuiLogger::workerCompleted(
            queue:       $this->queue,
            jobId:       $jobId,
            taskName:    (string) ($row['job_task']     ?? ''),
            status:      (string) ($row['status']       ?? 'unknown'),
            attempts:    (int)    ($row['failed']        ?? 0),
            maxAttempts: (int)    ($row['max_attempts']  ?? 1),
            createdAt:   (string) ($row['created']       ?? ''),
            fetchedAt:   (string) ($row['fetched']       ?? ''),
            finishedAt:  $finishedAt,
        );
    }

    private function enforceTimeout(ChildProcess $child): void
    {
        if (!$child->isTimedOut()) {
            return;
        }

        if (!$child->sigtermSent) {
            // First timeout threshold: ask the child to stop cleanly.
            $this->log("TIMEOUT job_id={$child->jobId}; sending SIGTERM");
            proc_terminate($child->resource, defined('SIGTERM') ? SIGTERM : 15);
            $child->sigtermSent = true;
            $child->sigtermSentAt = time();
            return;
        }

        if ($child->isGracePeriodExpired($this->sigtermGracePeriod)) {
            // Child ignored SIGTERM: escalate to a hard kill.
            $this->log("TIMEOUT job_id={$child->jobId}; sending SIGKILL");
            proc_terminate($child->resource, defined('SIGKILL') ? SIGKILL : 9);
        }
    }

    private function requeueCrashedJob(int $jobId, int $exitCode): void
    {
        $conn = ConnectionManager::get($this->resolvedConnectionName);
        $row = $conn->execute(
            'SELECT status, failed, max_attempts FROM queued_jobs WHERE id = ?',
            [$jobId]
        )->fetch('assoc');

        if (!$row || $row['status'] !== 'processing') {
            // The runner already updated the row; nothing left to recover.
            return;
        }

        $attempts = (int) $row['failed'];
        $maxAttempts = (int) $row['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $conn->execute(
                "UPDATE queued_jobs
                 SET status = 'dead', failure_message = ?, failed_at = ?, pid = NULL
                 WHERE id = ?",
                ["Child exited with code {$exitCode} and exhausted retries.", date('Y-m-d H:i:s'), $jobId]
            );
            $this->log("DEAD job_id={$jobId} exhausted after child exit");

            return;
        }

        $delay = BackoffSchedule::delayFor($attempts);
        $availableAt = date('Y-m-d H:i:s', time() + $delay);
        $conn->execute(
            "UPDATE queued_jobs
             SET status = 'retry_wait', notbefore = ?, fetched = NULL, pid = NULL,
                 failure_message = ?
             WHERE id = ?",
            [$availableAt, "Child process exited with code {$exitCode}.", $jobId]
        );
        $this->log("REQUEUED job_id={$jobId} after child exit (exit_code={$exitCode})");
    }

    private function flushOutput(ChildProcess $child): void
    {
        $out = stream_get_contents($child->pipes[1]);
        if ($out !== '' && $out !== false) {
            echo $out;
            // Keep TuiLogger's line counter in sync so redraw cursor math stays correct.
            TuiLogger::addLogLines(substr_count($out, "\n"));
        }

        $err = stream_get_contents($child->pipes[2]);
        if ($err !== '' && $err !== false) {
            fwrite(STDERR, $err);
        }
    }

    private function closePipes(ChildProcess $child): void
    {
        foreach ($child->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private function log(string $message): void
    {
        TuiLogger::coordinator($this->queue, $message);
    }

    private function resolveCakeBin(): string
    {
        // Support both CakePHP entrypoint styles found in different app setups.
        $candidates = [
            ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cake.php',
            ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cake',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return escapeshellarg($candidate);
            }
        }

        return escapeshellarg($candidates[0]);
    }
}
