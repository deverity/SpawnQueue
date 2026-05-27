<?php

declare(strict_types=1);

namespace SpawnQueue\ValueObject;

use Cake\Core\Configure;

/**
 * Resolved configuration for a specific queue.
 * Merges per-queue overrides on top of global SpawnQueue defaults.
 */
final class QueueConfig
{
    public function __construct(
        public readonly string $queue,
        public readonly int    $maxWorkers,
        public readonly int    $timeout,
        public readonly int    $maxAttempts,
        public readonly float  $pollInterval,
        public readonly int    $shutdownTimeout,
        public readonly int    $stuckJobTimeout,
        public readonly int    $stuckCheckInterval,
        public readonly int    $processHeartbeatInterval,
        public readonly int    $processStaleTimeout,
        public readonly int    $sigtermGracePeriod,
    ) {}

    public function with(array $overrides): self
    {
        return new self(
            queue:                    $overrides['queue']                    ?? $this->queue,
            maxWorkers:               $overrides['maxWorkers']               ?? $this->maxWorkers,
            timeout:                  $overrides['timeout']                  ?? $this->timeout,
            maxAttempts:              $overrides['maxAttempts']              ?? $this->maxAttempts,
            pollInterval:             $overrides['pollInterval']             ?? $this->pollInterval,
            shutdownTimeout:          $overrides['shutdownTimeout']          ?? $this->shutdownTimeout,
            stuckJobTimeout:          $overrides['stuckJobTimeout']          ?? $this->stuckJobTimeout,
            stuckCheckInterval:       $overrides['stuckCheckInterval']       ?? $this->stuckCheckInterval,
            processHeartbeatInterval: $overrides['processHeartbeatInterval'] ?? $this->processHeartbeatInterval,
            processStaleTimeout:      $overrides['processStaleTimeout']      ?? $this->processStaleTimeout,
            sigtermGracePeriod:       $overrides['sigtermGracePeriod']       ?? $this->sigtermGracePeriod,
        );
    }

    public static function forQueue(string $queue): self
    {
        $global   = Configure::read('SpawnQueue') ?? [];
        $perQueue = $global['queues'][$queue] ?? [];

        return new self(
            queue:              $queue,
            maxWorkers:         (int)   ($perQueue['max_workers']    ?? 3),
            timeout:            (int)   ($perQueue['timeout']        ?? $global['default_timeout']      ?? 120),
            maxAttempts:        (int)   ($perQueue['max_attempts']   ?? $global['default_max_attempts'] ?? 5),
            pollInterval:       (float) ($global['poll_interval']    ?? 1),
            shutdownTimeout:    (int)   ($global['shutdown_timeout'] ?? 30),
            stuckJobTimeout:    (int)   ($global['stuck_job_timeout']    ?? 300),
            stuckCheckInterval: (int)   ($global['stuck_check_interval'] ?? 60),
            processHeartbeatInterval: (int) ($global['process_heartbeat_interval'] ?? 5),
            processStaleTimeout:      (int) ($global['process_stale_timeout']      ?? 120),
            sigtermGracePeriod:       (int) ($global['sigterm_grace_period']        ?? 5),
        );
    }
}
