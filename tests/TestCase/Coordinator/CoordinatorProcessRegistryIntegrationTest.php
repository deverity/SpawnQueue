<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Coordinator;

use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Coordinator\CoordinatorProcessRegistry;

class CoordinatorProcessRegistryIntegrationTest extends TestCase
{
    private CoordinatorProcessRegistry $registry;

    protected function setUp(): void
    {
        if (!ConnectionManager::getConfig('test')) {
            $this->markTestSkipped('No test DB configured. Set DB_TEST_DSN to enable integration tests.');
        }

        $this->registry = new CoordinatorProcessRegistry('test');
        $this->ensureTable();
        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (ConnectionManager::getConfig('test')) {
            $this->truncate();
        }
    }

    // ── register() ────────────────────────────────────────────────────────────

    public function testRegisterCreatesProcess(): void
    {
        $this->registry->register('worker-1', 'default');

        $row = $this->fetchProcess('worker-1');

        $this->assertNotNull($row);
        $this->assertSame('worker-1', $row['worker_id']);
        $this->assertSame('default',  $row['queue']);
        $this->assertSame('running',  $row['status']);
        $this->assertNull($row['stopped_at']);
    }

    public function testRegisterUpdatesExistingProcess(): void
    {
        $this->registry->register('worker-1', 'default');
        $this->registry->register('worker-1', 'emails');

        $row = $this->fetchProcess('worker-1');

        $this->assertSame('emails',  $row['queue']);
        $this->assertSame('running', $row['status']);
        $this->assertNull($row['stopped_at']);
    }

    // ── heartbeat() ───────────────────────────────────────────────────────────

    public function testHeartbeatUpdatesTimestamp(): void
    {
        $this->registry->register('worker-1', 'default');

        $past = date('Y-m-d H:i:s', time() - 300);
        ConnectionManager::get('test')->execute(
            'UPDATE queue_processes SET heartbeat_at = ? WHERE worker_id = ?',
            [$past, 'worker-1']
        );

        $this->registry->heartbeat('worker-1');

        $row = $this->fetchProcess('worker-1');
        $this->assertNotSame($past, $row['heartbeat_at'], 'heartbeat_at must be refreshed');
        $this->assertSame('running', $row['status']);
    }

    // ── stop() ────────────────────────────────────────────────────────────────

    public function testStopMarksProcessAsStopped(): void
    {
        $this->registry->register('worker-1', 'default');
        $this->registry->stop('worker-1');

        $row = $this->fetchProcess('worker-1');

        $this->assertSame('stopped', $row['status']);
        $this->assertNotNull($row['stopped_at']);
    }

    // ── pruneDeadProcesses() ──────────────────────────────────────────────────

    public function testPruneDeletesStaleRunningProcesses(): void
    {
        $staleHeartbeat = date('Y-m-d H:i:s', time() - 600);
        $now = date('Y-m-d H:i:s');

        ConnectionManager::get('test')->insert('queue_processes', [
            'worker_id'    => 'stale-worker',
            'queue'        => 'default',
            'host'         => 'some-host',
            'pid'          => null,
            'status'       => 'running',
            'started_at'   => $staleHeartbeat,
            'heartbeat_at' => $staleHeartbeat,
            'stopped_at'   => null,
            'created'      => $now,
            'modified'     => $staleHeartbeat,
        ]);

        $deleted = $this->registry->pruneDeadProcesses('default', 30);

        $this->assertSame(1, $deleted);
        $this->assertNull($this->fetchProcess('stale-worker'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchProcess(string $workerId): ?array
    {
        $row = ConnectionManager::get('test')
            ->execute('SELECT * FROM queue_processes WHERE worker_id = ?', [$workerId])
            ->fetch('assoc');

        return $row ?: null;
    }

    private function ensureTable(): void
    {
        ConnectionManager::get('test')->execute('
            CREATE TABLE IF NOT EXISTS queue_processes (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                worker_id    VARCHAR(100)  NOT NULL,
                queue        VARCHAR(100)  NOT NULL,
                host         VARCHAR(255)  NOT NULL,
                pid          INT           NULL,
                status       VARCHAR(50)   NOT NULL DEFAULT \'running\',
                started_at   DATETIME      NULL,
                heartbeat_at DATETIME      NULL,
                stopped_at   DATETIME      NULL,
                created      DATETIME      NOT NULL,
                modified     DATETIME      NOT NULL,
                UNIQUE KEY uq_worker_id (worker_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    private function truncate(): void
    {
        ConnectionManager::get('test')->execute('DELETE FROM queue_processes');
    }
}
