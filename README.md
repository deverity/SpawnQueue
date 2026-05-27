# SpawnQueue — CakePHP Queue Plugin

A robust queue engine for CakePHP 4 that runs **each job in its own isolated process**, eliminating the most common failure modes of long-lived single-process workers.

## Why SpawnQueue?

| Problem | Solution |
|---|---|
| One bad job crashes the whole worker | Each job is a separate process — failure is contained |
| Deploy requires manual worker restart | Graceful shutdown: coordinator drains current jobs, then exits cleanly |
| Long-running jobs lock up other work | Multiple queues with independent concurrency |
| Worker running stale code after deploy | Short-lived child processes always load the latest code |
| No control over retry timing | Exponential backoff + configurable max attempts per queue |

## Requirements

- PHP **8.1+**
- CakePHP **4.5+**
- MySQL **8.0+** or MariaDB **10.6+** (for `SELECT … FOR UPDATE SKIP LOCKED`)
  Older versions fall back automatically — see [Claim Strategy](#claim-strategy)
- Linux **recommended** for production (graceful shutdown via POSIX signals)

## Installation

### Via Composer

```bash
composer require lsl/spawn-queue
```

Load the plugin in `src/Application.php`:

```php
$this->addPlugin('SpawnQueue', ['routes' => false]);
```

### Local / in-monorepo development

Add the namespace to your app's `composer.json` autoload:

```json
"SpawnQueue\\": "plugins/SpawnQueue/src/"
```

```bash
composer dump-autoload
```

### Run the migration

SpawnQueue auto-detects whether `dereuromark/cakephp-queue` is already installed:

- **Table exists** → adds only the 4 missing columns (`queue`, `max_attempts`, `pid`, `failed_at`)
- **Fresh install** → creates the full `queued_jobs` table from scratch

```bash
php bin/cake migrations migrate --plugin SpawnQueue
```

---

## Configuration

Override defaults in `config/app_local.php` or any file loaded in your bootstrap:

```php
Configure::write('SpawnQueue', [
    'poll_interval'        => 1,       // seconds between polling cycles when idle
    'shutdown_timeout'     => 30,      // seconds to wait for jobs on graceful shutdown
    'stuck_job_timeout'    => 300,     // seconds before a processing job is considered stuck
    'stuck_check_interval' => 60,      // how often the coordinator checks for stuck jobs
    'default_timeout'      => 120,     // per-job execution timeout (seconds)
    'default_max_attempts' => 5,
    'connection'           => 'default', // CakePHP connection name for all queue DB operations

    'queues' => [
        'default' => ['max_workers' => 3,  'timeout' => 120,  'max_attempts' => 5],
        'emails'  => ['max_workers' => 4,  'timeout' => 60,   'max_attempts' => 5],
        'imports' => ['max_workers' => 1,  'timeout' => 1800, 'max_attempts' => 3],
    ],
]);
```

---

## Creating Handlers

Implement `JobHandlerInterface` for new-style handlers:

```php
use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;
use SpawnQueue\Handler\JobHandlerInterface;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

class SendEmailJobHandler implements JobHandlerInterface
{
    use \Cake\ORM\Locator\LocatorAwareTrait;

    public static function queue(): string
    {
        return 'emails';
    }

    public function handle(JobData $job): JobResult
    {
        $to = $job->payload['to'] ?? null;

        if (!$to) {
            // Permanent failure — will NOT retry
            throw new NonRetryableJobException('Missing "to" in payload');
        }

        try {
            $this->sendEmail($to, $job->payload);
            return JobResult::success();
        } catch (\RuntimeException $e) {
            // Temporary failure — will retry with backoff
            throw new RetryableJobException('Transport failed: ' . $e->getMessage());
        }
    }
}
```

> **Dependency note:** Handlers are instantiated with `new ClassName()`.
> Use `LocatorAwareTrait`, `ConnectionManager`, or other CakePHP service locators
> for dependencies — constructor injection is not supported in this version.

### Exception / return reference

| Thrown / returned | Behaviour |
|---|---|
| `RetryableJobException($msg)` | Re-queues with automatic exponential backoff |
| `RetryableJobException($msg, retryAfterSeconds: 300)` | Re-queues with explicit delay |
| `NonRetryableJobException($msg)` | Marks as `failed` immediately, no retry |
| Any other `\Throwable` | Treated as retryable (safe default) |
| `JobResult::success()` | Marks as `done` |
| `JobResult::retry($error)` | Same as `RetryableJobException` |
| `JobResult::fail($error)` | Same as `NonRetryableJobException` |

---

## Legacy dereuromark/cakephp-queue Tasks

Existing tasks that extend `Queue\Queue\Task` work without any modification.
SpawnQueue wraps them automatically via `LegacyTaskAdapter`:

```php
// This task keeps working exactly as before:
class MyLegacyTask extends \Queue\Queue\Task
{
    public function run(array $data, int $jobId): void
    {
        // your existing code
    }
}

// Enqueue it:
QueueService::push(MyLegacyTask::class, $data); // queue defaults to "default"
QueueService::push('default', MyLegacyTask::class, $data); // explicit legacy form
```

---

## Enqueuing Jobs

```php
use SpawnQueue\Service\QueueService;

// Simple push
QueueService::push(SendEmailJobHandler::class, ['to' => 'user@example.com']);

// With options
QueueService::push(GenerateReportHandler::class, $payload, [
    'priority'     => 8,                      // 1–10, higher = first (default: 5)
    'max_attempts' => 3,                      // override queue default
    'delay'        => 120,                    // seconds from now
    'available_at' => '2026-04-01 08:00:00', // absolute datetime (overrides delay)
    'reference'    => 'report-42',
]);

// Scheduled job — syntactic sugar
QueueService::pushAt(GenerateReportHandler::class, $payload, '2026-04-01 08:00:00');
```

---

## Running the Coordinator

Run one coordinator per queue when you want separate OS processes, independent
restart control, or stronger isolation between high-traffic queues:

```bash
# Start a coordinator for the "emails" queue
php bin/cake queue:work emails --max-workers=4

# Override timeout for this run
php bin/cake queue:work imports --max-workers=1 --timeout=1800

# "default" queue also picks up legacy jobs with no queue set
php bin/cake queue:work default --max-workers=3
```

For smaller deployments, `queue:work-all` starts one long-running process that
manages every configured queue:

```bash
# Start one SuperCoordinator for all queues in SpawnQueue.queues
php bin/cake queue:work-all
```

`queue:work-all` reads queue names from `Configure::read('SpawnQueue.queues')`.
Both config shapes are supported:

```php
// Associative: keys are queue names.
'queues' => [
    'default' => ['max_workers' => 3, 'timeout' => 120],
    'emails'  => ['max_workers' => 4, 'timeout' => 60],
    'imports' => ['max_workers' => 1, 'timeout' => 1800],
],

// Sequential: values are queue names and each queue uses global defaults.
'queues' => ['default', 'emails', 'imports'],
```

If no queues are configured, `queue:work-all` falls back to `default`.
Internally it still creates one `QueueCoordinator` per queue, each with its own
worker pool and timeout settings, but all coordinators share a single parent
process and one combined TUI dashboard.

Use `queue:work-all` when operational simplicity matters more than per-queue
process isolation. Use separate `queue:work <queue>` processes when one queue
has heavy traffic, long-running jobs, or different restart/deploy needs.

## Commands Reference

| Command | Description |
|---|---|
| `queue:work <queue>` | Start coordinator (`--max-workers=N`, `--timeout=N`) |
| `queue:work-all` | Start one SuperCoordinator for all configured queues |
| `queue:run-job --job-id=N` | Run one job (internal — called by coordinator) |
| `queue:stats [--queue=name]` | Job counts by queue and status |
| `queue:requeue-stuck` | Recover jobs stuck in `processing` (`--queue`, `--timeout`) |
| `queue:retry-failed` | Re-queue `failed`/`dead` jobs (`--queue`, `--status`, `--limit`) |
| `queue:cleanup` | Delete old terminal jobs (`--days=30`, `--status`) |

---

## Job States

```
pending ──► processing ──► done
               │
               ├──► retry_wait ──► (back to eligible)
               ├──► failed      (non-retryable or manual mark)
               └──► dead        (exhausted max_attempts)

Manual:   cancelled
```

---

## Production Setup

### Supervisor (recommended)

Single process for all queues:

```ini
; /etc/supervisor/conf.d/spawnqueue.conf

[program:spawnqueue]
command=php /var/www/app/bin/cake queue:work-all
directory=/var/www/app
autostart=true
autorestart=true
stopwaitsecs=40          ; must exceed max(shutdown_timeout across queues) + sigterm_grace_period
                         ; all queues drain concurrently, so total wait = max, not sum
stdout_logfile=/var/log/spawnqueue/all.log
stderr_logfile=/var/log/spawnqueue/all.log
user=www-data
```

Separate process per queue:

```ini
; /etc/supervisor/conf.d/spawnqueue.conf

[program:spawnqueue-emails]
command=php /var/www/app/bin/cake queue:work emails --max-workers=4
directory=/var/www/app
autostart=true
autorestart=true
stopwaitsecs=35          ; must be > shutdown_timeout to allow graceful drain
stdout_logfile=/var/log/spawnqueue/emails.log
stderr_logfile=/var/log/spawnqueue/emails.log
user=www-data

[program:spawnqueue-imports]
command=php /var/www/app/bin/cake queue:work imports --max-workers=1 --timeout=1800
directory=/var/www/app
autostart=true
autorestart=true
stopwaitsecs=35
stdout_logfile=/var/log/spawnqueue/imports.log
stderr_logfile=/var/log/spawnqueue/imports.log
user=www-data
```

### systemd

Single process for all queues:

```ini
; /etc/systemd/system/spawnqueue.service
[Unit]
Description=SpawnQueue SuperCoordinator
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=php /var/www/app/bin/cake queue:work-all
Restart=always
RestartSec=5
KillMode=mixed
TimeoutStopSec=40        ; must exceed max(shutdown_timeout across queues) + sigterm_grace_period

[Install]
WantedBy=multi-user.target
```

Separate process per queue:

```ini
; /etc/systemd/system/spawnqueue-emails.service
[Unit]
Description=SpawnQueue coordinator — emails
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=php /var/www/app/bin/cake queue:work emails --max-workers=4
Restart=always
RestartSec=5
KillMode=mixed     ; SIGTERM to main, SIGKILL fallback
TimeoutStopSec=35

[Install]
WantedBy=multi-user.target
```

### Deploy without downtime

```bash
# Coordinator stops claiming, waits for active children, then exits.
# Supervisor/systemd restarts it — new process loads fresh code.

supervisorctl restart spawnqueue-emails

# Or manually:
kill -TERM $(pgrep -f "queue:work emails")
```

---

## Architecture

```
CakePHP App
  └── QueueService::push()  →  INSERT into queued_jobs

SuperCoordinator  (optional, one process for all configured queues)
  QueueCoordinator[]  one coordinator per queue, shared event loop

Coordinator  (one per queue, long-lived; standalone or inside SuperCoordinator)
  ├── JobClaimer          atomic SELECT FOR UPDATE SKIP LOCKED + UPDATE
  ├── ChildProcessManager spawn / reap / timeout / SIGTERM+SIGKILL
  └── StuckJobResolver    recover jobs stuck in processing

Child Worker  (one per job, short-lived)
  ├── JobRunner           load → resolve handler → execute → persist
  ├── JobHandlerInterface new-style handler contract
  └── LegacyTaskAdapter   bridge for Queue\Queue\Task subclasses
```

### Claim Strategy

SpawnQueue tries `SELECT … FOR UPDATE SKIP LOCKED` first.
On older databases (MySQL < 8.0, MariaDB < 10.6) it falls back to a conditional
`UPDATE` — safe but may cause minor contention on very busy queues.

---

## Migrating from dereuromark/cakephp-queue

1. Install SpawnQueue and run the migration (adds columns, keeps all existing jobs)
2. Keep dereuromark installed — your app still uses it to write jobs
3. Stop the old `bin/cake queue:worker` processes
4. Start SpawnQueue coordinators
5. Gradually migrate task classes to implement `JobHandlerInterface`
6. Once all tasks are migrated, remove the dereuromark dependency

SpawnQueue picks up **both** old-style (no `queue` column) and new-style jobs.
Old jobs are routed to the `default` coordinator.

---

## Backoff Schedule

| Attempt | Delay before next try |
|---|---|
| 1 | 10 seconds |
| 2 | 30 seconds |
| 3 | 2 minutes |
| 4 | 10 minutes |
| 5+ | 30 minutes |

Override for a specific failure: `throw new RetryableJobException($msg, retryAfterSeconds: 3600);`

---

## License

MIT
