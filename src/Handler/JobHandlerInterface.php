<?php

declare(strict_types=1);

namespace SpawnQueue\Handler;

use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

/**
 * Contract for all SpawnQueue job handlers.
 *
 * Handler classes own their logical queue. QueueService reads queue() when a
 * new-style handler is enqueued, so callers do not need to pass a queue name
 * and cannot accidentally route the handler to the wrong queue.
 *
 * Example:
 *
 *   use SpawnQueue\Exception\NonRetryableJobException;
 *   use SpawnQueue\Exception\RetryableJobException;
 *   use SpawnQueue\Handler\JobHandlerInterface;
 *   use SpawnQueue\Service\QueueService;
 *   use SpawnQueue\ValueObject\JobData;
 *   use SpawnQueue\Worker\JobResult;
 *
 *   class SendEmailJobHandler implements JobHandlerInterface
 *   {
 *       public static function queue(): string
 *       {
 *           return 'emails';
 *       }
 *
 *       public function handle(JobData $job): JobResult
 *       {
 *           $to = $job->payload['to'] ?? null;
 *           if (!$to) {
 *               throw new NonRetryableJobException('Missing "to" in payload');
 *           }
 *           // ... send email ...
 *           return JobResult::success();
 *       }
 *   }
 *
 * Enqueue it from your application:
 *
 *   QueueService::push(SendEmailJobHandler::class, ['to' => 'foo@bar.com']);
 */
interface JobHandlerInterface
{
    /**
     * Return the logical queue name this handler belongs to.
     *
     * The returned value is stored in queued_jobs.queue at enqueue time. Return
     * "default" when the application only uses the default queue.
     */
    public static function queue(): string;

    /**
     * Execute the queued job.
     *
     * Return JobResult::success(), JobResult::retry(), or JobResult::fail().
     * Throwing RetryableJobException or NonRetryableJobException has the same
     * meaning as returning retry or fail respectively. Other throwables are
     * treated as retryable by the worker.
     */
    public function handle(JobData $job): JobResult;
}
