<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Re-queues jobs that are in 'failed' or 'dead' state.
 *
 * Usage:
 *   php bin/cake queue:retry-failed
 *   php bin/cake queue:retry-failed --queue=emails
 *   php bin/cake queue:retry-failed --status=dead --limit=50
 */
class QueueRetryFailedCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:retry-failed';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Re-queue failed or dead jobs for another processing attempt.')
            ->addOption('queue', [
                'help'    => 'Limit to a specific queue.',
                'default' => null,
            ])
            ->addOption('status', [
                'help'    => 'Source status to retry: failed|dead (default: failed).',
                'default' => 'failed',
            ])
            ->addOption('limit', [
                'help'    => 'Maximum number of jobs to re-queue (default: 100).',
                'default' => '100',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $queue  = $args->getOption('queue');
        $status = $args->getOption('status');
        $limit  = max(1, (int) $args->getOption('limit'));

        if (!in_array($status, ['failed', 'dead'], true)) {
            $io->error("Invalid --status '{$status}'. Use 'failed' or 'dead'.");
            return self::CODE_ERROR;
        }

        $conn   = ConnectionManager::get('default');
        $now    = date('Y-m-d H:i:s');
        $params = [$status];

        $queueSql = '';
        if ($queue !== null) {
            $queueSql = " AND (queue = ? OR (queue IS NULL AND ? = 'default'))";
            $params[] = $queue;
            $params[] = $queue;
        }

        $ids = $conn->execute(
            "SELECT id FROM queued_jobs
             WHERE status = ?{$queueSql}
             ORDER BY id ASC
             LIMIT {$limit}",
            $params
        )->fetchAll('assoc');

        if (empty($ids)) {
            $io->out("No jobs with status '{$status}' found.");
            return self::CODE_SUCCESS;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $conn->execute(
            "UPDATE queued_jobs
             SET status = 'pending', failed = 0, failure_message = NULL,
                 failed_at = NULL, fetched = NULL, completed = NULL,
                 notbefore = ?, pid = NULL
             WHERE id IN ({$placeholders})",
            [$now, ...array_column($ids, 'id')]
        );

        $count = count($ids);
        $io->success("Re-queued {$count} job(s) (was '{$status}').");

        return self::CODE_SUCCESS;
    }
}
