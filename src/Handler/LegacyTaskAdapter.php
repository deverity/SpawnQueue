<?php

declare(strict_types=1);

namespace SpawnQueue\Handler;

use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

/**
 * Bridges dereuromark/cakephp-queue Task classes to JobHandlerInterface.
 *
 * Legacy tasks implement: run(array $data, int $jobId): void.
 * They extend Queue\Queue\Task and can be instantiated with no arguments.
 *
 * SpawnQueue does not import Queue\Queue\Task directly, so this adapter works
 * via duck-typing and does not create a hard dependency on that package.
 *
 * Exception mapping:
 *   RetryableJobException    => JobResult::retry()
 *   NonRetryableJobException => JobResult::fail()
 *   Any other \Throwable     => JobResult::retry() (treat unknown errors as transient)
 */
class LegacyTaskAdapter implements JobHandlerInterface
{
    public function __construct(private readonly string $taskClass) {}

    /**
     * Legacy tasks do not declare a queue themselves.
     *
     * QueueService stores them in "default" unless callers pass an explicit
     * queue name through the legacy push(queue, task, payload) form.
     */
    public static function queue(): string
    {
        return 'default';
    }

    public function handle(JobData $job): JobResult
    {
        try {
            $task = new $this->taskClass();

            // run() is defined by Queue\Queue\Task subclasses.
            // We call it via duck-typing, with no hard dependency on that interface.
            $task->run($job->payload, $job->id);

            return JobResult::success();
        } catch (NonRetryableJobException $e) {
            return JobResult::fail($e->getMessage());
        } catch (RetryableJobException $e) {
            return JobResult::retry($e->getMessage(), $e->getRetryAfterSeconds());
        } catch (\Throwable $e) {
            // Unknown exceptions from legacy tasks are treated as retryable so
            // the job gets a chance to recover from transient failures.
            return JobResult::retry(sprintf('[%s] %s', $e::class, $e->getMessage()));
        }
    }
}
