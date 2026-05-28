<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Smart migration for SpawnQueue.
 *
 * ┌─ queued_jobs already exists? (dereuromark/cakephp-queue)
 * │     YES  →  add only the missing SpawnQueue columns (delta upgrade)
 * │     NO   →  create the full table from scratch
 * └──────────────────────────────────────────────────────────────────
 *
 * The table schema deliberately mirrors the dereuromark column names
 * (job_task, data, failed, notbefore, fetched, workerkey …) so that
 * both systems can coexist on the same table during a transition period.
 * SpawnQueue adds four new columns on top: queue, max_attempts, pid, failed_at.
 */
class SpawnQueueSchema extends AbstractMigration
{
    // New columns SpawnQueue needs on top of the dereuromark baseline.
    private const NEW_COLUMNS = ['queue', 'max_attempts', 'pid', 'failed_at'];

    public function up(): void
    {
        if ($this->hasTable('queued_jobs')) {
            $this->deltaUpgrade();
        } else {
            $this->createFresh();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('queued_jobs')) {
            return;
        }

        // Only drop the columns SpawnQueue added — never touch the rest.
        $existingColumns = $this->getExistingColumns('queued_jobs');

        $table = $this->table('queued_jobs');

        foreach (self::NEW_COLUMNS as $col) {
            if (in_array($col, $existingColumns, true)) {
                $table->removeColumn($col);
            }
        }

        // Remove the index SpawnQueue created (ignore error if it doesn't exist).
        try {
            $table->removeIndex(['queue', 'status', 'notbefore'])->update();
        } catch (\Throwable) {
            // index may not exist on a partial rollback — safe to ignore
        }

        $table->update();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Add only the missing SpawnQueue columns to an existing dereuromark table. */
    private function deltaUpgrade(): void
    {
        $existingColumns = $this->getExistingColumns('queued_jobs');
        $table           = $this->table('queued_jobs');
        $changed         = false;
        $legacyMaxAttempts = $this->detectLegacyMaxAttempts();

        if (!in_array('queue', $existingColumns, true)) {
            $table->addColumn('queue', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => 'SpawnQueue logical queue name (null = default)',
            ]);
            $changed = true;
        }

        if (!in_array('max_attempts', $existingColumns, true)) {
            $table->addColumn('max_attempts', 'integer', [
                'null'    => false,
                'default' => $legacyMaxAttempts,
                'comment' => 'SpawnQueue max retry attempts for this job',
            ]);
            $changed = true;
        }

        if (!in_array('pid', $existingColumns, true)) {
            $table->addColumn('pid', 'integer', [
                'null'    => true,
                'comment' => 'SpawnQueue child process PID',
            ]);
            $changed = true;
        }

        if (!in_array('failed_at', $existingColumns, true)) {
            $table->addColumn('failed_at', 'datetime', [
                'null'    => true,
                'comment' => 'SpawnQueue timestamp of definitive failure',
            ]);
            $changed = true;
        }

        if ($changed) {
            $table->update();
        }

        // Add composite index for coordinator claim query (safe if already exists).
        try {
            $this->table('queued_jobs')
                ->addIndex(
                    ['queue', 'status', 'notbefore'],
                    ['name' => 'idx_spawnqueue_claim']
                )
                ->update();
        } catch (\Throwable) {
            // index already exists — nothing to do
        }
    }

    /** Create the full queued_jobs table for a fresh install (no dereuromark). */
    private function createFresh(): void
    {
        $this->table('queued_jobs')
            // ── SpawnQueue additions ──────────────────────────────────────────
            ->addColumn('queue', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => 'Logical queue name (null = default)',
            ])
            // ── dereuromark-compatible columns ────────────────────────────────
            ->addColumn('job_task', 'string', [
                'limit' => 200,
                'null'  => false,
                'comment' => 'Handler class name',
            ])
            ->addColumn('data', 'text', ['null' => true, 'comment' => 'JSON payload'])
            ->addColumn('job_group', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('reference', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('notbefore', 'datetime', ['null' => true, 'comment' => 'available_at'])
            ->addColumn('fetched', 'datetime', ['null' => true, 'comment' => 'reserved_at'])
            ->addColumn('completed', 'datetime', ['null' => true, 'comment' => 'finished_at'])
            ->addColumn('progress', 'float', ['null' => true])
            ->addColumn('failed', 'integer', [
                'null'    => false,
                'default' => 0,
                'comment' => 'Attempt counter (incremented on each claim)',
            ])
            ->addColumn('max_attempts', 'integer', ['null' => false, 'default' => 5])
            ->addColumn('failure_message', 'text', ['null' => true, 'comment' => 'last_error'])
            ->addColumn('workerkey', 'string', ['limit' => 100, 'null' => true, 'comment' => 'worker_id'])
            ->addColumn('pid', 'integer', ['null' => true, 'comment' => 'Child process PID'])
            ->addColumn('status', 'string', [
                'limit'   => 50,
                'null'    => true,
                'default' => 'pending',
                'comment' => 'pending|processing|retry_wait|done|failed|dead|cancelled',
            ])
            ->addColumn('priority', 'integer', ['null' => false, 'default' => 5])
            ->addColumn('failed_at', 'datetime', ['null' => true])
            // ── indexes ───────────────────────────────────────────────────────
            ->addIndex(['queue', 'status', 'notbefore'], ['name' => 'idx_spawnqueue_claim'])
            ->addIndex(['status'])
            ->addIndex(['workerkey'])
            ->create();
    }

    /** Infer the retry ceiling used by legacy rows before adding max_attempts. */
    private function detectLegacyMaxAttempts(): int
    {
        $rows = $this->fetchAll('SELECT MAX(failed) AS max_failed FROM queued_jobs');
        $maxFailed = (int) ($rows[0]['max_failed'] ?? 0);

        return $maxFailed > 0 ? $maxFailed : 5;
    }

    /** Returns an array of existing column names for a table. */
    private function getExistingColumns(string $table): array
    {
        $rows = $this->fetchAll("SHOW COLUMNS FROM `{$table}`");

        return array_column($rows, 'Field');
    }
}
