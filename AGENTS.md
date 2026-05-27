# AGENTS.md — SpawnQueue

CakePHP 4.5+ plugin. Runs each queued job in its own isolated child process.
Atomic DB claiming via `SELECT … FOR UPDATE SKIP LOCKED` (MySQL 8+ / MariaDB 10.6+; auto-falls back).

## Requirements

- PHP 8.1+, CakePHP ^4.5, MySQL 8.0+ or MariaDB 10.6+
- Composer package: `deverity/spawn-queue`
- Plugin class: `SpawnQueue\SpawnQueuePlugin`

## Source Layout

```
src/
  Handler/
    JobHandlerInterface.php   — contract for all job handlers
    LegacyTaskAdapter.php     — wraps Queue\Queue\Task subclasses
    EmailJobHandler.php       — built-in email handler (Queue.Email task_map key)
  ValueObject/
    JobData.php               — immutable queued_jobs row (readonly props)
    QueueConfig.php           — resolved per-queue config (readonly props)
  Worker/
    JobResult.php             — immutable result: success/retry/fail
    JobRunner.php             — resolves handler, executes, persists status
  Service/
    QueueService.php          — application-facing push() / pushAt() facade
  Coordinator/
    QueueCoordinator.php      — long-lived per-queue process
    SuperCoordinator.php      — manages multiple QueueCoordinators
    ChildProcess.php          — wraps one child worker
    ChildProcessManager.php   — spawn/reap/timeout children
    JobClaimer.php            — atomic SELECT+UPDATE claim
    StuckJobResolver.php      — re-queues jobs stuck in processing
    FailedJobReviver.php      — re-queues failed/dead jobs
    CoordinatorProcessRegistry.php — queue_processes heartbeat table
  Console/
    TuiLogger.php             — all-static TUI dashboard (static state)
  Utility/
    ProcessChecker.php        — isAlive(int $pid): bool (cross-platform)
  Exception/
    RetryableJobException.php
    NonRetryableJobException.php
    JobTimeoutException.php
  SpawnQueuePlugin.php        — bootstrap + console registration
config/
  spawn_queue.php             — default config (loaded on bootstrap if SpawnQueue key absent)
  Migrations/
    20260331120000_SpawnQueueSchema.php  — smart: delta upgrade OR fresh create
    20260331123000_SpawnQueueProcesses.php
```

## DB Schema: queued_jobs

| Column           | Type     | Notes                                              |
|------------------|----------|----------------------------------------------------|
| id               | int PK   |                                                    |
| queue            | string   | SpawnQueue addition; NULL → 'default'              |
| job_task         | string   | Handler class name (→ JobData.task)                |
| data             | text     | JSON payload (→ JobData.payload)                   |
| failed           | int      | Attempt counter incremented on each claim          |
| max_attempts     | int      | SpawnQueue addition; default 5                     |
| priority         | int      | 1–10, higher runs first; default 5                 |
| status           | string   | pending|processing|retry_wait|done|failed|dead|cancelled |
| notbefore        | datetime | available_at (→ JobData.availableAt)               |
| fetched          | datetime | reserved_at (→ JobData.reservedAt)                 |
| completed        | datetime | finished_at                                        |
| failure_message  | text     | last_error (→ JobData.lastError)                   |
| workerkey        | string   | worker_id (→ JobData.workerId)                     |
| pid              | int      | SpawnQueue addition; child PID (→ JobData.pid)     |
| failed_at        | datetime | SpawnQueue addition; definitive failure timestamp  |
| job_group        | string   | legacy dereuromark field                           |
| reference        | string   | external reference                                 |
| created          | datetime |                                                    |

Claim index: `idx_spawnqueue_claim (queue, status, notbefore)`.

## JobData (src/ValueObject/JobData.php)

Immutable. Always construct via `JobData::fromRow(array $row)`.

```php
final class JobData {
    public readonly int     $id;
    public readonly string  $queue;       // never null; '' or null normalized to 'default'
    public readonly string  $task;        // from job_task
    public readonly array   $payload;     // decoded from data (JSON first, PHP serialize fallback)
    public readonly int     $attempts;    // from failed
    public readonly int     $maxAttempts; // from max_attempts
    public readonly int     $priority;
    public readonly string  $workerId;    // from workerkey
    public readonly ?int    $pid;
    public readonly ?string $lastError;   // from failure_message
    public readonly ?string $availableAt; // from notbefore
    public readonly ?string $reservedAt;  // from fetched
}
```

`hasExhaustedAttempts(): bool` → `$attempts >= $maxAttempts`

## Config Keys (config/spawn_queue.php)

All under `Configure::read('SpawnQueue')`:

| Key                        | Type  | Default | Meaning                                         |
|----------------------------|-------|---------|-------------------------------------------------|
| poll_interval              | int   | 1       | Seconds to sleep when idle                      |
| shutdown_timeout           | int   | 30      | Seconds to wait for children on graceful stop   |
| stuck_job_timeout          | int   | 300     | Seconds before a processing job is stuck        |
| stuck_check_interval       | int   | 60      | How often stuck check runs                      |
| process_heartbeat_interval | int   | 5       | Seconds between heartbeat updates               |
| process_stale_timeout      | int   | 120     | Seconds without heartbeat = stale coordinator   |
| default_timeout            | int   | 120     | Per-job execution timeout (no queue override)   |
| default_max_attempts       | int   | 5       | Max retries (no queue override)                 |
| task_map                   | array | —       | `['Queue.Email' => EmailJobHandler::class, …]`  |
| queues                     | array | —       | Per-queue overrides (see below)                 |

Per-queue keys (`queues.<name>`): `max_workers`, `timeout`, `max_attempts`.

## QueueConfig (src/ValueObject/QueueConfig.php)

```php
QueueConfig::forQueue(string $queue): self
```

Merges `queues.<queue>` overrides on top of global defaults. Read-only props:
`queue`, `maxWorkers`, `timeout`, `maxAttempts`, `pollInterval`,
`shutdownTimeout`, `stuckJobTimeout`, `stuckCheckInterval`,
`processHeartbeatInterval`, `processStaleTimeout`.

## JobHandlerInterface

```php
interface JobHandlerInterface {
    public static function queue(): string;          // logical queue name
    public function handle(JobData $job): JobResult; // execute the job
}
```

Handlers are instantiated with `new ClassName()`. Use `LocatorAwareTrait` or
`ConnectionManager` for dependencies — no constructor injection.

## JobResult (src/Worker/JobResult.php)

Immutable. Named constructors only:

```php
JobResult::success()                           // done
JobResult::retry(string $error, int $retryAfterSeconds = 0) // retry with backoff (0 = auto)
JobResult::fail(string $error)                 // permanent failure, no retry
```

Readonly props: `bool $success`, `bool $shouldRetry`, `?string $error`, `int $retryAfterSeconds`.

## Exception Contract

| Thrown                          | Effect                                          |
|---------------------------------|-------------------------------------------------|
| `RetryableJobException($msg)`   | Re-queues with automatic exponential backoff    |
| `RetryableJobException($msg, retryAfterSeconds: N)` | Re-queues with explicit delay |
| `NonRetryableJobException($msg)`| Marks `failed` immediately                     |
| Any other `\Throwable`          | Treated as retryable (safe default)             |

`RetryableJobException::getRetryAfterSeconds(): int` (0 = auto backoff).

## Backoff Schedule

| Attempt | Delay    |
|---------|----------|
| 1       | 10 s     |
| 2       | 30 s     |
| 3       | 2 min    |
| 4       | 10 min   |
| 5+      | 30 min   |

## QueueService::push() — Call Forms

```php
// New-style: handler declares its own queue
QueueService::push(HandlerClass::class, $payload, $options, $connection);

// Legacy explicit queue
QueueService::push('emails', LegacyTask::class, $payload, $options, $connection);

// Legacy default queue
QueueService::push(LegacyTask::class, $payload, $options, $connection);
```

Options array keys: `priority` (int 1-10, default 5), `max_attempts` (int),
`delay` (int seconds), `available_at` (string datetime, overrides delay),
`reference` (string), `job_group` (string).

Returns `int` (inserted `queued_jobs.id`).

```php
QueueService::pushAt(HandlerClass::class, $payload, '2026-04-01 08:00:00', $options);
```

## Job State Machine

```
pending ──► processing ──► done
               │
               ├──► retry_wait ──► (back to pending-eligible)
               ├──► failed      (non-retryable or max_attempts reached)
               └──► dead        (exhausted max_attempts)
Manual: cancelled
```

## CLI Commands

| Command                   | Purpose                                                   |
|---------------------------|-----------------------------------------------------------|
| `queue:work <queue>`      | Start coordinator (`--max-workers=N`, `--timeout=N`)      |
| `queue:work-all`          | Start SuperCoordinator for all `SpawnQueue.queues`        |
| `queue:run-job`           | Internal — coordinator calls this per job (`--job-id=N`) |
| `queue:stats`             | Job counts by queue/status (`--queue=name`)               |
| `queue:requeue-stuck`     | Recover stuck processing jobs (`--queue`, `--timeout`)    |
| `queue:retry-failed`      | Re-queue failed/dead jobs (`--queue`, `--status`, `--limit`) |
| `queue:cleanup`           | Delete old terminal jobs (`--days=30`, `--status`)        |

## Architecture Layers

```
App → QueueService::push() → INSERT queued_jobs

SuperCoordinator (optional one-process-all-queues)
  QueueCoordinator[] — one per queue, shared event loop

QueueCoordinator (long-lived, one per queue)
  JobClaimer          — atomic SELECT FOR UPDATE SKIP LOCKED + UPDATE
  ChildProcessManager — spawn / reap / timeout via SIGTERM+SIGKILL
  StuckJobResolver    — recover jobs stuck > stuck_job_timeout

Child Worker (short-lived, one per job)
  JobRunner           — load row → resolve handler → execute → persist
  JobHandlerInterface — new-style handler contract
  LegacyTaskAdapter   — bridge for Queue\Queue\Task subclasses
```

## LegacyTaskAdapter

Wraps any class that has a `run(array $data, int $jobId): void` method.
Handler throws `RetryableJobException` → result is retryable.
Handler throws `NonRetryableJobException` → result is non-retryable.
Any other `\Throwable` → retryable.
`queue()` always returns `'default'`.

## EmailJobHandler

Built-in handler for `Queue.Email` task_map key. Payload keys:
`mailer_class` (string), `settings` (array), `action` (string, optional).
Transport exceptions → `RetryableJobException`.
Missing/invalid payload → `NonRetryableJobException`.

## Plugin Bootstrap

`SpawnQueuePlugin::bootstrap()` loads `config/spawn_queue.php` only if
`Configure::check('SpawnQueue')` is false (never overwrites existing config).

## Testing Notes

- PHPUnit 9, schema `https://schema.phpunit.de/9.5/phpunit.xsd`
- Do NOT use CakePHP test utilities (`ConsoleIntegrationTestTrait`, etc.);
  CakePHP classes (Configure, Plugin, CommandCollection, Mailer, etc.) are fine.
- `TuiLogger` has all-static private state; reset via `ReflectionClass` between tests.
  Use `putenv('NO_COLOR=1')` to disable ANSI output; `ob_start()`/`ob_end_clean()` to capture echo.
- Unit tests: `tests/TestCase/`; Integration tests require `DB_TEST_DSN` env var.
- `SpawnQueuePlugin` tests: register `BasePlugin(['name'=>'SpawnQueue','path'=>...])` in
  `setUp()` so `Configure::load('SpawnQueue.spawn_queue')` can resolve the config path.
- `QueueConfig::forQueue()` reads from `Configure` — write test config before calling.
- Stub classes live in `tests/Stub/`: `FakeMailer`, `ThrowingMailer`, `ActionMailer`,
  `SuccessTask`, `RetryableTask`, `NonRetryableTask`, `UnknownErrorTask`,
  `ExplicitRetryTask`, `NoRunTask`, `NewStyleHandler`, `RetryHandler`, `FailHandler`,
  `UndefinedQueueHandler`.
