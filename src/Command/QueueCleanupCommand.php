<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Removes old terminal jobs (done / failed / dead / cancelled) from the table.
 *
 * Usage:
 *   php bin/cake queue:cleanup
 *   php bin/cake queue:cleanup --days=7
 *   php bin/cake queue:cleanup --status=done --days=1
 */
class QueueCleanupCommand extends Command
{
    private const TERMINAL_STATUSES = ['done', 'failed', 'dead', 'cancelled'];

    public static function defaultName(): string
    {
        return 'queue:cleanup';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Delete old terminal jobs (done/failed/dead/cancelled) from the queue table.')
            ->addOption('days', [
                'help'    => 'Delete jobs older than N days (default: 30).',
                'default' => '30',
            ])
            ->addOption('status', [
                'help'    => 'Limit to a specific terminal status. Defaults to all.',
                'default' => null,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $days   = max(1, (int) $args->getOption('days'));
        $status = $args->getOption('status');

        $validStatuses = self::TERMINAL_STATUSES;
        if ($status !== null && !in_array($status, $validStatuses, true)) {
            $io->error("Invalid status '{$status}'. Valid values: " . implode(', ', $validStatuses));
            return self::CODE_ERROR;
        }

        $conn      = ConnectionManager::get('default');
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        if ($status !== null) {
            $result = $conn->execute(
                "DELETE FROM queued_jobs WHERE status = ? AND created <= ?",
                [$status, $threshold]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($validStatuses), '?'));
            $result = $conn->execute(
                "DELETE FROM queued_jobs WHERE status IN ({$placeholders}) AND created <= ?",
                [...$validStatuses, $threshold]
            );
        }

        $deleted = $result->rowCount();
        $io->success("Deleted {$deleted} job(s) older than {$days} day(s).");

        return self::CODE_SUCCESS;
    }
}
