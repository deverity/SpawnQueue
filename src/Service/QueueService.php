<?php

declare(strict_types=1);

namespace SpawnQueue\Service;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use InvalidArgumentException;
use SpawnQueue\Handler\JobHandlerInterface;

/**
 * Application-facing facade for enqueuing jobs.
 *
 * New-style handlers declare their own queue through JobHandlerInterface::queue().
 * Legacy dereuromark/cakephp-queue tasks may still be enqueued with an explicit
 * queue, or without one. Legacy tasks without an explicit queue use "default".
 * Empty queue names and the literal string "undefined" are also normalized to
 * "default".
 *
 * Supported call forms:
 *
 *   use SpawnQueue\Service\QueueService;
 *
 *   QueueService::push(SendEmailJobHandler::class, ['to' => 'foo@bar.com']);
 *   QueueService::push('App\Queue\Task\MyTask', $data);
 *   QueueService::push('default', 'App\Queue\Task\MyTask', $data);
 *   QueueService::push(GenerateReportHandler::class, $payload, [
 *       'priority'     => 8,
 *       'max_attempts' => 3,
 *       'delay'        => 60,
 *       'available_at' => '2026-04-01 08:00:00',
 *   ]);
 */
class QueueService
{
    /**
     * Insert a pending job into queued_jobs.
     *
     * New-style form:
     *
     *   QueueService::push(HandlerClass::class, $payload, $options, $connection);
     *
     * Legacy/explicit-queue form:
     *
     *   QueueService::push('default', TaskClass::class, $payload, $options, $connection);
     *
     * Legacy/default-queue form:
     *
     *   QueueService::push(TaskClass::class, $payload, $options, $connection);
     *
     * Recognized options:
     *
     * - priority: int 1-10, higher runs first. Defaults to 5.
     * - max_attempts: int retry limit. Defaults to queue config, then global config.
     * - delay: int seconds to delay execution from now.
     * - available_at: string absolute datetime; overrides delay.
     * - reference: string external reference stored with the job.
     * - job_group: string legacy dereuromark job_group field.
     *
     * @param string $queueOrTask Queue name in the legacy form, or task/handler class in the new form.
     * @param string|array<string, mixed> $taskOrPayload Task class in the legacy form, or payload in the new form.
     * @param array<string, mixed> $payloadOrOptions Payload in the legacy form, or options in the new form.
     * @param array<string, mixed>|string $optionsOrConnection Options array, or connection name in the new form.
     * @param string $connection CakePHP connection name when it is not passed as the fourth argument.
     * @return int Inserted queued_jobs.id.
     */
    public static function push(
        string $queueOrTask,
        string|array $taskOrPayload = [],
        array $payloadOrOptions = [],
        array|string $optionsOrConnection = [],
        string $connection = 'default'
    ): int {
        [$queue, $task, $payload, $options, $connection] = self::normalizePushArguments(
            $queueOrTask,
            $taskOrPayload,
            $payloadOrOptions,
            $optionsOrConnection,
            $connection
        );

        /** @var Connection $conn */
        $conn = ConnectionManager::get($connection);
        $now = date('Y-m-d H:i:s');
        $config = Configure::read('SpawnQueue') ?? [];
        $queueConfig = $config['queues'][$queue] ?? [];

        // available_at can come directly or be derived from a relative delay.
        $availableAt = self::resolveAvailableAt($options);

        $data = [
            'queue' => $queue,
            'job_task' => $task,
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'job_group' => $options['job_group'] ?? null,
            'reference' => $options['reference'] ?? null,
            'status' => 'pending',
            'priority' => (int) ($options['priority'] ?? 5),
            'max_attempts' => (int) ($options['max_attempts'] ?? $queueConfig['max_attempts'] ?? $config['default_max_attempts'] ?? 5),
            'failed' => 0,
            'notbefore' => $availableAt,
            'created' => $now,
        ];

        $conn->insert('queued_jobs', $data);

        return (int) $conn->getDriver()->lastInsertId();
    }

    /**
     * Insert a job scheduled for a specific datetime.
     *
     * New-style form:
     *
     *   QueueService::pushAt(HandlerClass::class, $payload, '2030-01-01 08:00:00', $options, $connection);
     *
     * Legacy/explicit-queue form:
     *
     *   QueueService::pushAt('default', TaskClass::class, $payload, '2030-01-01 08:00:00', $options, $connection);
     *
     * @param string $queueOrTask Queue name in the legacy form, or task/handler class in the new form.
     * @param string|array<string, mixed> $taskOrPayload Task class in the legacy form, or payload in the new form.
     * @param array<string, mixed>|string $payloadOrAvailableAt Payload in the legacy form, or datetime in the new form.
     * @param string|array<string, mixed> $availableAtOrOptions Datetime in the legacy form, or options in the new form.
     * @param array<string, mixed> $options Options for the explicit-queue form.
     * @param string $connection CakePHP connection name.
     * @return int Inserted queued_jobs.id.
     */
    public static function pushAt(
        string $queueOrTask,
        string|array $taskOrPayload,
        array|string $payloadOrAvailableAt,
        string|array $availableAtOrOptions = [],
        array $options = [],
        string $connection = 'default'
    ): int {
        if (is_string($taskOrPayload)) {
            if (!is_array($payloadOrAvailableAt) || !is_string($availableAtOrOptions)) {
                throw new InvalidArgumentException(
                    'QueueService::pushAt() expects queue, task, payload array, and available_at string.'
                );
            }

            return self::push(
                $queueOrTask,
                $taskOrPayload,
                $payloadOrAvailableAt,
                array_merge($options, ['available_at' => $availableAtOrOptions]),
                $connection
            );
        }

        if (!is_string($payloadOrAvailableAt)) {
            throw new InvalidArgumentException(
                'QueueService::pushAt() expects task, payload array, and available_at string.'
            );
        }

        $pushOptions = is_array($availableAtOrOptions) ? $availableAtOrOptions : [];

        return self::push(
            $queueOrTask,
            $taskOrPayload,
            array_merge($pushOptions, ['available_at' => $payloadOrAvailableAt]),
            $connection
        );
    }

    /**
     * Normalize the overloaded push() call forms into insert-ready values.
     *
     * @return array{0:string, 1:string, 2:array<string, mixed>, 3:array<string, mixed>, 4:string}
     */
    private static function normalizePushArguments(
        string $queueOrTask,
        string|array $taskOrPayload,
        array $payloadOrOptions,
        array|string $optionsOrConnection,
        string $connection
    ): array {
        if (is_string($taskOrPayload)) {
            $options = is_array($optionsOrConnection) ? $optionsOrConnection : [];
            $resolvedConnection = is_string($optionsOrConnection) ? $optionsOrConnection : $connection;

            return [
                self::normalizeQueue($queueOrTask),
                $taskOrPayload,
                $payloadOrOptions,
                $options,
                $resolvedConnection,
            ];
        }

        $options = $payloadOrOptions;
        $resolvedConnection = is_string($optionsOrConnection) ? $optionsOrConnection : $connection;
        $task = $queueOrTask;

        return [
            self::resolveQueueForTask($task),
            $task,
            $taskOrPayload,
            $options,
            $resolvedConnection,
        ];
    }

    /**
     * Resolve the queue for a task class when no explicit queue was passed.
     *
     * JobHandlerInterface implementations provide the queue themselves. Legacy
     * task classes and unknown class strings default to the "default" queue.
     */
    private static function resolveQueueForTask(string $task): string
    {
        if (class_exists($task) && is_subclass_of($task, JobHandlerInterface::class)) {
            return self::normalizeQueue($task::queue());
        }

        return 'default';
    }

    /**
     * Normalize frontend/legacy placeholders to the default queue.
     */
    private static function normalizeQueue(string $queue): string
    {
        $queue = trim($queue);

        if ($queue === '' || strtolower($queue) === 'undefined') {
            return 'default';
        }

        return $queue;
    }

    /**
     * Resolve the notbefore value from absolute or relative scheduling options.
     *
     * @param array<string, mixed> $options
     */
    private static function resolveAvailableAt(array $options): ?string
    {
        if (isset($options['available_at'])) {
            return $options['available_at'];
        }

        if (isset($options['delay']) && (int) $options['delay'] > 0) {
            return date('Y-m-d H:i:s', time() + (int) $options['delay']);
        }

        return null;
    }
}
