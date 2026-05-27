<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

use SpawnQueue\Console\TuiLogger;
use SpawnQueue\ValueObject\QueueConfig;

/**
 * Multiplexed coordinator that manages multiple queues in a single PHP process.
 *
 * Used by the queue:work-all command. Each queue still gets its own
 * QueueCoordinator instance (with its own child-process pool, job claimer,
 * stuck-job resolver, etc.). SuperCoordinator drives them all through a shared
 * event loop, sleeping only when every queue was idle on the same iteration.
 *
 * Lifecycle:
 *   1. Install signal handlers once for the whole process.
 *   2. Call initialize() on every coordinator.
 *   3. Print the combined multi-queue TUI dashboard.
 *   4. Call logStarted() on every coordinator.
 *   5. Loop: tick each coordinator (without internal sleep); sleep once if all were idle.
 *   6. On shutdown: cascade initiateShutdown() to every coordinator,
 *      then call gracefulShutdown() sequentially.
 */
class SuperCoordinator
{
    private bool $shutdown = false;

    /** @var QueueCoordinator[] */
    private array $coordinators = [];

    /** @var QueueConfig[] */
    private array $configs;

    /**
     * @param QueueConfig[] $configs  One entry per queue to manage.
     */
    public function __construct(array $configs)
    {
        $this->configs = $configs;

        foreach ($configs as $config) {
            $this->coordinators[] = new QueueCoordinator($config);
        }
    }

    public function run(): void
    {
        $this->installSignalHandlers();

        foreach ($this->coordinators as $coordinator) {
            $coordinator->initialize();
        }

        TuiLogger::initDashboard($this->configs);

        foreach ($this->coordinators as $coordinator) {
            $coordinator->logStarted();
        }

        while (!$this->shutdown) {
            $anyWork = false;

            foreach ($this->coordinators as $coordinator) {
                if (!$coordinator->isRunning()) {
                    continue;
                }

                try {
                    // tick() with allowSleep=false — we handle sleep centrally.
                    if ($coordinator->tick(allowSleep: false)) {
                        $anyWork = true;
                    }
                } catch (\Throwable $e) {
                    TuiLogger::coordinator(
                        $coordinator->getConfig()->queue,
                        'ERROR in tick: ' . $e->getMessage() . ' — retrying in 5s'
                    );
                    sleep(5);
                }
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Sleep once across all queues when none had work this cycle.
            // Use the smallest poll_interval among all configs.
            if (!$anyWork) {
                $pollInterval = min(array_map(
                    fn(QueueConfig $c) => $c->pollInterval,
                    $this->configs
                ));
                usleep((int) ($pollInterval * 1_000_000));
            }
        }

        $this->drainAll();
    }

    public function initiateShutdown(): void
    {
        $this->shutdown = true;

        foreach ($this->coordinators as $coordinator) {
            $coordinator->initiateShutdown();
        }
    }

    private function drainAll(): void
    {
        // All queues drain concurrently under a single shared deadline equal to
        // the longest individual shutdown_timeout. Total wait = max, not sum.
        $shutdownTimeout = max(array_map(
            fn(QueueCoordinator $c) => $c->getConfig()->shutdownTimeout,
            $this->coordinators
        ));
        $deadline = time() + $shutdownTimeout;

        // Phase 1: let all queues drain naturally.
        while (time() < $deadline) {
            $anyRunning = false;
            foreach ($this->coordinators as $coordinator) {
                if (!$coordinator->hasPendingChildren()) {
                    continue;
                }
                $coordinator->reapChildren();
                $anyRunning = true;
            }
            if (!$anyRunning) {
                break;
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            usleep(200_000);
        }

        // Phase 2: SIGTERM any children still running across all queues.
        foreach ($this->coordinators as $coordinator) {
            if ($coordinator->hasPendingChildren()) {
                $coordinator->terminateChildren();
            }
        }

        $graceTimeout = max(array_map(
            fn(QueueCoordinator $c) => $c->getConfig()->sigtermGracePeriod,
            $this->coordinators
        ));
        $termDeadline = time() + $graceTimeout;
        while (time() < $termDeadline) {
            $anyRunning = false;
            foreach ($this->coordinators as $coordinator) {
                if (!$coordinator->hasPendingChildren()) {
                    continue;
                }
                $coordinator->reapChildren();
                $anyRunning = true;
            }
            if (!$anyRunning) {
                break;
            }
            usleep(200_000);
        }

        // Phase 3: SIGKILL last resort.
        foreach ($this->coordinators as $coordinator) {
            if ($coordinator->hasPendingChildren()) {
                $coordinator->killChildren();
            }
        }

        // Finalize all coordinators (update process registry, log STOPPED).
        foreach ($this->coordinators as $coordinator) {
            $coordinator->finalizeShutdown();
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->initiateShutdown();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
