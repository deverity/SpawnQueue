<?php

declare(strict_types=1);

/**
 * SpawnQueue default configuration.
 *
 * To override values in your application add to config/app_local.php:
 *
 *   Configure::write('SpawnQueue.queues.emails.max_workers', 8);
 *
 * Or load a full override file in bootstrap.php:
 *
 *   Configure::load('spawn_queue_local');
 */

return [
    'SpawnQueue' => [
        // Seconds the coordinator sleeps when no jobs are available.
        'poll_interval' => 1,

        // Seconds the coordinator waits for in-flight children during graceful shutdown.
        'shutdown_timeout' => 30,

        // Jobs stuck in "processing" beyond this many seconds are re-queued.
        'stuck_job_timeout' => 300,

        // How often (seconds) the coordinator runs the stuck-job check.
        'stuck_check_interval' => 60,

        // How often a coordinator updates queue_processes.heartbeat_at.
        'process_heartbeat_interval' => 5,

        // Consider a coordinator stale after this many seconds without heartbeat.
        'process_stale_timeout' => 120,

        // Fallback timeout (seconds) for a single job if the queue has no override.
        'default_timeout' => 120,

        // Fallback max attempts if not set per-queue.
        'default_max_attempts' => 5,

        // CakePHP connection name used for all queue DB operations.
        // Change this when your app stores jobs on a non-default connection.
        'connection' => 'default',

        // Seconds between SIGTERM and SIGKILL when a job exceeds its timeout
        // or when the coordinator is shutting down and children don't exit cleanly.
        'sigterm_grace_period' => 5,

        // Terminal output mode.
        //   'lines' — scrolling log lines only, no dashboard (default)
        //   'tui'   — live dashboard only, log lines suppressed (htop-like)
        // Can also be set per-run with the --show option on queue:work / queue:work-all.
        'show_type' => 'lines',

        // Map legacy task strings to handler class names.
        // Keys are the exact strings stored in queued_jobs.job_task.
        // Add entries to override or extend the default mapping.
        'task_map' => [
            'Queue.Email' => \SpawnQueue\Handler\EmailJobHandler::class,
        ],

        // Per-queue overrides. Values here take precedence over the defaults above.
        'queues' => [
            'default' => [
                'max_workers'  => 3,
                'timeout'      => 120,
                'max_attempts' => 5,
            ],
        ],
    ],
];
