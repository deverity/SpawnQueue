<?php

declare(strict_types=1);

namespace SpawnQueue\Worker;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use SpawnQueue\Console\TuiLogger;
use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;
use SpawnQueue\Handler\JobHandlerInterface;
use SpawnQueue\Handler\LegacyTaskAdapter;
use SpawnQueue\ValueObject\BackoffSchedule;
use SpawnQueue\ValueObject\JobData;

/**
 * Runs inside a child process for exactly one job.
 *
 * Responsibilities:
 *   1. Load the job from the database by ID.
 *   2. Validate claim ownership when worker metadata is provided.
 *   3. Resolve and instantiate the handler.
 *   4. Execute the handler.
 *   5. Persist the result: done / retry_wait / failed / dead.
 *   6. Return an exit code understood by the coordinator.
 *
 * The coordinator treats a non-zero exit code as "runner could not safely
 * handle this row" and performs defensive recovery.
 */
class JobRunner
{
    private const STATUS_DONE = 'done';
    private const STATUS_RETRY_WAIT = 'retry_wait';
    private const STATUS_FAILED = 'failed';
    private const STATUS_DEAD = 'dead';

    private readonly string $connectionName;

    public function __construct()
    {
        $this->connectionName = Configure::read('SpawnQueue.connection') ?? 'default';
    }

    public function run(int $jobId, ?string $expectedWorkerId = null, bool $force = false): int
    {
        $startedAt = microtime(true);

        try {
            $job = $this->loadJob($jobId, $expectedWorkerId, $force);
        } catch (\Throwable $e) {
            $this->log("CRITICAL: could not load job #{$jobId}: " . $e->getMessage());
            return 1;
        }

        $this->log(sprintf(
            'START job_id=%d queue=%s task=%s attempt=%d/%d',
            $job->id,
            $job->queue,
            basename(str_replace('\\', '/', $job->task)),
            $job->attempts,
            $job->maxAttempts
        ));

        try {
            $handler = $this->resolveHandler($job);
            $result = $handler->handle($job);
        } catch (NonRetryableJobException $e) {
            $result = JobResult::fail($e->getMessage());
        } catch (RetryableJobException $e) {
            $result = JobResult::retry($e->getMessage(), $e->getRetryAfterSeconds());
        } catch (\Throwable $e) {
            $result = JobResult::retry(sprintf('[%s] %s', $e::class, $e->getMessage()));
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->persistResult($job, $result);

        $status = $this->resolveStatus($job, $result);
        $this->log(sprintf(
            'END job_id=%d status=%s duration_ms=%d%s',
            $job->id,
            $status,
            $durationMs,
            $result->error ? ' error="' . $result->error . '"' : ''
        ));

        return 0;
    }

    private function loadJob(int $jobId, ?string $expectedWorkerId, bool $force): JobData
    {
        $conn = ConnectionManager::get($this->connectionName);

        if ($force) {
            // Debug/manual mode: run whatever row currently exists.
            $row = $conn->execute('SELECT * FROM queued_jobs WHERE id = ?', [$jobId])->fetch('assoc');
        } else {
            // Normal coordinator mode: only run jobs that are actively claimed
            // and, when provided, owned by the expected worker key.
            $sql = 'SELECT * FROM queued_jobs WHERE id = ? AND status = ?';
            $params = [$jobId, 'processing'];

            if ($expectedWorkerId !== null) {
                $sql .= ' AND workerkey = ?';
                $params[] = $expectedWorkerId;
            }

            $row = $conn->execute($sql, $params)->fetch('assoc');
        }

        if (!$row) {
            throw new \RuntimeException(
                $force
                    ? "Job #{$jobId} not found."
                    : "Job #{$jobId} is not in processing for this worker."
            );
        }

        return JobData::fromRow($row);
    }

    private function resolveHandler(JobData $job): JobHandlerInterface
    {
        $class = $this->resolveHandlerClassName($job->task);

        if ($class === null) {
            throw new NonRetryableJobException("Handler class not found: {$job->task}");
        }

        // Prefer a static factory so handlers can inject their own dependencies:
        //   public static function create(): static { return new static(new MyService()); }
        $instance = method_exists($class, 'create') ? $class::create() : new $class();

        if ($instance instanceof JobHandlerInterface) {
            return $instance;
        }

        // Legacy dereuromark task support through duck typing.
        if (method_exists($instance, 'run')) {
            return new LegacyTaskAdapter($class);
        }

        throw new NonRetryableJobException(
            "Class {$class} does not implement JobHandlerInterface and has no run() method."
        );
    }

    /**
     * Resolve the task string stored in queued_jobs to an actual PHP class.
     *
     * Accepted formats:
     *   - Fully-qualified handler class names
     *   - Fully-qualified legacy task class names
      *   - Legacy plugin/task notation, for example:
     *       Queue.Email -> SpawnQueue\Handler\EmailJobHandler
     *   - Short legacy task names stored by older queue rows, for example:
     *       DocumentosDigitais -> App\Queue\Task\DocumentosDigitaisTask
     */
    private function resolveHandlerClassName(string $task): ?string
    {
        $candidates = [$task];

        // Check config-driven task map first (exact match on the full task string).
        $taskMap = Configure::read('SpawnQueue.task_map', []);
        if (is_array($taskMap) && isset($taskMap[$task])) {
            $candidates[] = $taskMap[$task];
        }

        if (str_contains($task, '.')) {
            [$plugin, $taskName] = explode('.', $task, 2);
            $normalizedTaskName = preg_replace('/Task$/', '', $taskName) ?? $taskName;

            $candidates[] = $plugin . '\\Queue\\Task\\' . $taskName;
            $candidates[] = $plugin . '\\Queue\\Task\\' . $normalizedTaskName . 'Task';
        }

        if (!str_contains($task, '\\') && !str_contains($task, '.')) {
            $shortName = preg_replace('/Task$/', '', $task) ?? $task;

            $candidates[] = 'App\\Queue\\Task\\' . $task;
            $candidates[] = 'App\\Queue\\Task\\' . $shortName . 'Task';
        }

        foreach (array_unique($candidates) as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function persistResult(JobData $job, JobResult $result): void
    {
        $conn = ConnectionManager::get($this->connectionName);
        $now = date('Y-m-d H:i:s');

        if ($result->success) {
            // Clear previous failure metadata in case this row was retried.
            $conn->execute(
                'UPDATE queued_jobs
                 SET status = ?, completed = ?, progress = 1, pid = NULL,
                     failure_message = NULL, failed_at = NULL
                 WHERE id = ?',
                [self::STATUS_DONE, $now, $job->id]
            );
            return;
        }

        if ($result->shouldRetry && !$job->hasExhaustedAttempts()) {
            // Handlers may provide an explicit retry delay; otherwise use queue backoff.
            $delay = $result->retryAfterSeconds > 0
                ? $result->retryAfterSeconds
                : BackoffSchedule::delayFor($job->attempts);
            $availableAt = date('Y-m-d H:i:s', time() + $delay);

            $conn->execute(
                'UPDATE queued_jobs
                 SET status = ?, notbefore = ?, failure_message = ?,
                     fetched = NULL, pid = NULL
                 WHERE id = ?',
                [self::STATUS_RETRY_WAIT, $availableAt, $result->error, $job->id]
            );
            return;
        }

        // Exhausted or non-retryable -> permanent terminal state.
        $finalStatus = $job->hasExhaustedAttempts() ? self::STATUS_DEAD : self::STATUS_FAILED;

        $conn->execute(
            'UPDATE queued_jobs
             SET status = ?, failure_message = ?, failed_at = ?, pid = NULL
             WHERE id = ?',
            [$finalStatus, $result->error, $now, $job->id]
        );
    }

    private function resolveStatus(JobData $job, JobResult $result): string
    {
        if ($result->success) {
            return self::STATUS_DONE;
        }

        if ($result->shouldRetry && !$job->hasExhaustedAttempts()) {
            return self::STATUS_RETRY_WAIT;
        }

        return $job->hasExhaustedAttempts() ? self::STATUS_DEAD : self::STATUS_FAILED;
    }

    private function log(string $message): void
    {
        TuiLogger::runner($message);
    }
}
