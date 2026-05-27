<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use SpawnQueue\Coordinator\StuckJobResolver;

/**
 * Manually re-queues jobs that are stuck in processing.
 *
 * Usage:
 *   php bin/cake queue:requeue-stuck
 *   php bin/cake queue:requeue-stuck --queue=emails --timeout=120
 */
class QueueRequeueStuckCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:requeue-stuck';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Re-queue jobs stuck in "processing" state.')
            ->addOption('queue', [
                'help' => 'Limit to a specific queue. Omit to scan all queues.',
                'default' => null,
            ])
            ->addOption('timeout', [
                'help' => 'Consider a job stuck after this many seconds (default: 300).',
                'default' => '300',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $queue = $args->getOption('queue');
        $timeout = (int) $args->getOption('timeout');

        $resolver = new StuckJobResolver();
        $recovered = $resolver->resolve(is_string($queue) ? $queue : null, $timeout);

        $queueLabel = is_string($queue) && $queue !== '' ? $queue : 'all queues';
        $io->success("Recovered {$recovered} stuck job(s) from {$queueLabel}.");

        return self::CODE_SUCCESS;
    }
}
