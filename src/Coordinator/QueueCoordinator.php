<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use SpawnQueue\Console\TuiLogger;
use SpawnQueue\ValueObject\QueueConfig;

/**
 * Main coordinator loop for a single queue.
 *
 * Lifecycle (standalone via run()):
 *   1. Install signal handlers (SIGTERM / SIGINT) for graceful shutdown.
 *   2. initialize() — register process, heartbeat.
 *   3. Loop via tick() until shutdown.
 *   4. gracefulShutdown() — drain children.
 *
 * Lifecycle (managed by SuperCoordinator):
 *   - SuperCoordinator calls initialize() once per coordinator.
 *   - SuperCoordinator calls tick(allowSleep: false) on each coordinator each cycle.
 *   - SuperCoordinator calls gracefulShutdown() when draining.
 *
 * The coordinator never executes business logic.
 * It only manages child processes and job lifecycle in the database.
 */
class QueueCoordinator
{
    private bool $shutdown = false;

    private int $lastHeartbeatAt = 0;

    private int $lastStuckCheck = 0;

    private string $workerId;

    private JobClaimer $claimer;

    private ChildProcessManager $childManager;

    private CoordinatorProcessRegistry $processRegistry;

    private FailedJobReviver $failedJobReviver;

    private StuckJobResolver $stuckResolver;

    public function __construct(private readonly QueueConfig $config)
    {
        $this->workerId = $this->generateWorkerId();

        $this->claimer = new JobClaimer();
        $this->childManager = new ChildProcessManager(
            timeout: $this->config->timeout,
            workerId: $this->workerId,
            queue: $this->config->queue,
            sigtermGracePeriod: $this->config->sigtermGracePeriod,
        );
        $this->processRegistry = new CoordinatorProcessRegistry();
        $this->failedJobReviver = new FailedJobReviver();
        $this->stuckResolver = new StuckJobResolver();
    }

    /**
     * Standalone entry point: installs signals, inits a single-queue dashboard, then loops.
     */
    public function run(): void
    {
        $this->installSignalHandlers();
        $this->initialize();

        TuiLogger::initDashboard([$this->config]);
        $this->logStarted();

        while (!$this->shutdown) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->log('ERROR in tick: ' . $e->getMessage() . ' — retrying in 5s');
                sleep(5);
            }
        }

        $this->gracefulShutdown();
    }

    /**
     * Register this coordinator in the process registry and set the heartbeat baseline.
     * Called once before the main loop (by run() or SuperCoordinator).
     */
    public function initialize(): void
    {
        $this->processRegistry->register($this->workerId, $this->config->queue);
        $this->lastHeartbeatAt = time();
    }

    /**
     * Log the START message. Separated from initialize() so SuperCoordinator can call
     * TuiLogger::initDashboard() for all queues before any START lines are emitted.
     */
    public function logStarted(): void
    {
        $this->log(sprintf(
            'START queue=%s max_workers=%d timeout=%ds worker_id=%s',
            $this->config->queue,
            $this->config->maxWorkers,
            $this->config->timeout,
            $this->workerId
        ));
    }

    /**
     * Execute one iteration of the coordinator loop.
     *
     * @param  bool $allowSleep  Pass false when called from SuperCoordinator (sleep is managed externally).
     * @return bool              True when at least one job was claimed this tick.
     */
    public function tick(bool $allowSleep = true): bool
    {
        $this->heartbeat();

        // 1. Reap finished children and enforce runtime limits.
        $this->childManager->reap();

        // 2. Fill available worker slots with newly claimed jobs.
        $claimed = 0;
        while (!$this->shutdown && $this->childManager->hasSlot($this->config->maxWorkers)) {
            $job = $this->claimer->claim($this->config->queue, $this->workerId);
            if ($job === null) {
                break;
            }

            try {
                $this->childManager->spawn($job);
                $claimed++;
            } catch (\Throwable $e) {
                $this->log("ERROR spawning job #{$job->id}: " . $e->getMessage());
                $this->claimer->release($job->id);
            }
        }

        // 3. Periodically recover abandoned jobs left in processing.
        if ((time() - $this->lastStuckCheck) >= $this->config->stuckCheckInterval) {
            $revived = $this->failedJobReviver->revive(
                $this->config->queue,
                $this->config->processStaleTimeout
            );
            $recovered = $this->stuckResolver->resolve(
                $this->config->queue,
                $this->config->stuckJobTimeout,
                $this->config->processStaleTimeout
            );
            $pruned = $this->processRegistry->pruneDeadProcesses(
                $this->config->queue,
                $this->config->processStaleTimeout
            );

            if ($revived > 0) {
                $this->log("FAILED_JOBS revived={$revived}");
            }

            if ($recovered > 0) {
                $this->log("STUCK_JOBS recovered={$recovered}");
            }

            if ($pruned > 0) {
                $this->log("PROCESS_REGISTRY pruned={$pruned}");
            }

            $this->lastStuckCheck = time();
        }

        // 4. Handle any pending signals in environments without async dispatch.
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        // 5. Sleep only when idle and the caller allows it (standalone mode).
        if ($allowSleep && $claimed === 0) {
            usleep((int) ($this->config->pollInterval * 1_000_000));
        }

        return $claimed > 0;
    }

    public function initiateShutdown(): void
    {
        $this->log('SHUTDOWN signal received; stopping new claims and draining active jobs.');
        $this->shutdown = true;
    }

    public function isRunning(): bool
    {
        return !$this->shutdown;
    }

    public function getConfig(): QueueConfig
    {
        return $this->config;
    }

    public function hasPendingChildren(): bool
    {
        return $this->childManager->count() > 0;
    }

    public function reapChildren(): void
    {
        $this->childManager->reap();
    }

    public function terminateChildren(): void
    {
        $this->log("SHUTDOWN deadline reached; sending SIGTERM to {$this->childManager->count()} remaining job(s).");
        $this->childManager->terminateAll();
    }

    public function killChildren(): void
    {
        $this->log("SHUTDOWN force-killing {$this->childManager->count()} remaining job(s).");
        $this->childManager->killAll();
    }

    public function finalizeShutdown(): void
    {
        $this->processRegistry->stop($this->workerId);
        $this->log('STOPPED queue=' . $this->config->queue);
    }

    public function gracefulShutdown(): void
    {
        $deadline = time() + $this->config->shutdownTimeout;

        $this->log(
            "SHUTDOWN draining {$this->childManager->count()} active job(s) until {$this->config->shutdownTimeout}s deadline."
        );

        while ($this->hasPendingChildren() && time() < $deadline) {
            $this->reapChildren();

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(200_000);
        }

        if ($this->hasPendingChildren()) {
            $this->terminateChildren();

            $termDeadline = time() + $this->config->sigtermGracePeriod;
            while ($this->hasPendingChildren() && time() < $termDeadline) {
                $this->reapChildren();
                usleep(200_000);
            }
        }

        if ($this->hasPendingChildren()) {
            $this->killChildren();
        }

        $this->finalizeShutdown();
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->log('WARN: pcntl extension not available; graceful shutdown via signal is disabled.');
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->initiateShutdown();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function generateWorkerId(): string
    {
        return sprintf(
            '%s:%d:%s',
            gethostname() ?: 'unknown',
            getmypid(),
            $this->config->queue
        );
    }

    private function heartbeat(): void
    {
        if ((time() - $this->lastHeartbeatAt) < $this->config->processHeartbeatInterval) {
            return;
        }

        $this->processRegistry->heartbeat($this->workerId);

        // Refresh the TUI pending panel every heartbeat cycle.
        TuiLogger::setPendingJobs(
            $this->config->queue,
            $this->claimer->peekPending($this->config->queue)
        );

        $this->lastHeartbeatAt = time();
    }

    private function log(string $message): void
    {
        TuiLogger::coordinator($this->config->queue, $message);
    }
}
