<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Displays a summary of the current queue state.
 *
 * Usage:
 *   php bin/cake queue:stats
 *   php bin/cake queue:stats --queue=emails
 */
class QueueStatsCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:stats';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Show job counts by queue and status.')
            ->addOption('queue', [
                'help'    => 'Filter to a specific queue.',
                'default' => null,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $conn  = ConnectionManager::get('default');
        $queue = $args->getOption('queue');

        $sql    = "SELECT COALESCE(queue, 'default') AS queue, status, COUNT(*) AS total
                   FROM queued_jobs";
        $params = [];

        if ($queue !== null) {
            $sql    .= " WHERE (queue = ? OR (queue IS NULL AND ? = 'default'))";
            $params  = [$queue, $queue];
        }

        $sql   .= " GROUP BY queue, status ORDER BY queue ASC, status ASC";
        $rows   = $conn->execute($sql, $params)->fetchAll('assoc');

        if (empty($rows)) {
            $io->out('No jobs found.');
            return self::CODE_SUCCESS;
        }

        $io->out('');
        $io->out(sprintf('%-20s %-20s %10s', 'Queue', 'Status', 'Count'));
        $io->out(str_repeat('-', 52));

        foreach ($rows as $row) {
            $io->out(sprintf(
                '%-20s %-20s %10s',
                $row['queue']  ?? '(null)',
                $row['status'] ?? '(null)',
                $row['total']
            ));
        }

        $io->out('');

        // Stuck jobs warning
        $stuckThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $stuck = $conn->execute(
            "SELECT COUNT(*) AS n FROM queued_jobs WHERE status = 'processing' AND fetched <= ?",
            [$stuckThreshold]
        )->fetch('assoc');

        if ((int) ($stuck['n'] ?? 0) > 0) {
            $io->warning("⚠  {$stuck['n']} job(s) appear stuck in 'processing' for > 5 minutes.");
            $io->warning("   Run: php bin/cake queue:requeue-stuck");
        }

        return self::CODE_SUCCESS;
    }
}
