<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

class SpawnQueueProcesses extends AbstractMigration
{
    /**
     * SpawnQueue columns required in queue_processes.
     * Each entry: column name => Phinx addColumn() options array.
     */
    private const REQUIRED_COLUMNS = [
        'worker_id'    => ['type' => 'string',   'limit' => 100,  'null' => false, 'default' => '', 'comment' => 'Coordinator identity: host:pid:queue'],
        'queue'        => ['type' => 'string',   'limit' => 100,  'null' => false, 'default' => 'default'],
        'host'         => ['type' => 'string',   'limit' => 191,  'null' => false, 'default' => ''],
        'pid'          => ['type' => 'integer',  'null' => true],
        'status'       => ['type' => 'string',   'limit' => 20,   'null' => false, 'default' => 'running', 'comment' => 'running|stopped'],
        'started_at'   => ['type' => 'datetime', 'null' => true],
        'heartbeat_at' => ['type' => 'datetime', 'null' => true,  'comment' => 'Last coordinator heartbeat'],
        'stopped_at'   => ['type' => 'datetime', 'null' => true],
        'created'      => ['type' => 'datetime', 'null' => true],
        'modified'     => ['type' => 'datetime', 'null' => true],
    ];

    public function up(): void
    {
        if (!$this->hasTable('queue_processes')) {
            // Fresh install — create the full table from scratch.
            $this->table('queue_processes', ['id' => false, 'primary_key' => ['worker_id']])
                ->addColumn('worker_id',    'string',   ['limit' => 100, 'null' => false, 'comment' => 'Coordinator identity: host:pid:queue'])
                ->addColumn('queue',        'string',   ['limit' => 100, 'null' => false])
                ->addColumn('host',         'string',   ['limit' => 191, 'null' => false])
                ->addColumn('pid',          'integer',  ['null' => true])
                ->addColumn('status',       'string',   ['limit' => 20,  'null' => false, 'default' => 'running', 'comment' => 'running|stopped'])
                ->addColumn('started_at',   'datetime', ['null' => false])
                ->addColumn('heartbeat_at', 'datetime', ['null' => false, 'comment' => 'Last coordinator heartbeat'])
                ->addColumn('stopped_at',   'datetime', ['null' => true])
                ->addColumn('created',      'datetime', ['null' => false])
                ->addColumn('modified',     'datetime', ['null' => false])
                ->addIndex(['queue', 'status', 'heartbeat_at'], ['name' => 'idx_spawnqueue_process_status'])
                ->create();

            return;
        }

        // The table already exists (possibly created by dereuromark/cakephp-queue
        // with a different schema). Add only the columns SpawnQueue needs that are
        // not yet present — never drop or rename existing columns.
        $existingColumns = $this->getExistingColumns('queue_processes');
        $table = $this->table('queue_processes');
        $changed = false;

        foreach (self::REQUIRED_COLUMNS as $column => $options) {
            if (in_array($column, $existingColumns, true)) {
                continue;
            }

            $table->addColumn($column, $options['type'], array_diff_key($options, ['type' => null]));
            $changed = true;
        }

        if ($changed) {
            $table->save();
        }

        // Fix legacy dereuromark columns that are NOT NULL without a default value.
        // SpawnQueue's INSERT does not populate these columns, causing MySQL to reject
        // the row. We only set a DEFAULT — we never drop or change the column type.
        $this->fixLegacyNotNullColumns($existingColumns);

        // Add the composite index if it doesn't already exist.
        if (!$this->hasIndex('queue_processes', 'idx_spawnqueue_process_status')) {
            $this->table('queue_processes')
                ->addIndex(['queue', 'status', 'heartbeat_at'], ['name' => 'idx_spawnqueue_process_status'])
                ->save();
        }
    }

    public function down(): void
    {
        // Only drop the table when SpawnQueue originally created it.
        // We detect this by checking for our specific index — dereuromark does not add it.
        if ($this->hasTable('queue_processes')
            && $this->hasIndex('queue_processes', 'idx_spawnqueue_process_status')
        ) {
            // If the table existed before (dereuromark), only remove the columns we added.
            // We cannot know for sure, so we only drop if there is no dereuromark PK column.
            $existingColumns = $this->getExistingColumns('queue_processes');
            $hasDereuromarkColumns = in_array('workerkey', $existingColumns, true)
                || in_array('terminate', $existingColumns, true);

            if ($hasDereuromarkColumns) {
                // Remove only our added columns; leave the rest intact.
                $table = $this->table('queue_processes');
                foreach (self::REQUIRED_COLUMNS as $column => $_) {
                    if (in_array($column, $existingColumns, true)
                        && !in_array($column, ['workerkey', 'terminate', 'server'], true)
                    ) {
                        $table->removeColumn($column);
                    }
                }
                $table->save();
            } else {
                $this->table('queue_processes')->drop()->save();
            }
        }
    }

    /** @return string[] */
    private function getExistingColumns(string $tableName): array
    {
        $rows = $this->fetchAll("SHOW COLUMNS FROM `{$tableName}`");

        return array_map(static fn(array $row): string => (string) $row['Field'], $rows);
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        $rows = $this->fetchAll(
            "SHOW INDEX FROM `{$tableName}` WHERE Key_name = '{$indexName}'"
        );

        return !empty($rows);
    }

    /**
     * Set a safe DEFAULT on legacy dereuromark columns that are NOT NULL without one.
     * SpawnQueue's INSERT into queue_processes does not include these columns, so MySQL
     * would reject the row. We use ALTER … SET DEFAULT rather than MODIFY to avoid
     * touching the column type or length.
     *
     * @param string[] $existingColumns
     */
    private function fixLegacyNotNullColumns(array $existingColumns): void
    {
        // Map of column name → default value to apply when the column exists and
        // currently has no default (dereuromark leaves workerkey with no default).
        $fixes = [
            'workerkey' => "''",
            'jobtype'   => "''",
            'server'    => "''",
        ];

        foreach ($fixes as $column => $default) {
            if (!in_array($column, $existingColumns, true)) {
                continue;
            }

            // Only set the default if the column does not already have one.
            if ($this->getColumnDefault('queue_processes', $column) !== null) {
                continue;
            }

            $this->execute(
                "ALTER TABLE `queue_processes` ALTER COLUMN `{$column}` SET DEFAULT {$default}"
            );
        }
    }

    /**
     * Returns the current DEFAULT value of a column, or null if none is set.
     */
    private function getColumnDefault(string $tableName, string $column): ?string
    {
        $rows = $this->fetchAll(
            "SHOW COLUMNS FROM `{$tableName}` WHERE Field = '{$column}'"
        );

        if (empty($rows)) {
            return null;
        }

        $default = $rows[0]['Default'] ?? null;

        // MySQL returns NULL for "no default" when the column is NOT NULL.
        // An empty string default is a valid value — we preserve that distinction.
        return $default;
    }
}
