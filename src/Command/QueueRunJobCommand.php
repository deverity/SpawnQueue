<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use SpawnQueue\Worker\JobRunner;

/**
 * Executes exactly one job in the current process.
 *
 * Called internally by the coordinator:
 *   php bin/cake queue:run-job 42 --worker-id=host:123:emails
 *
 * Exit codes:
 *   0 = job handled cleanly (success, retry, or permanent failure; DB updated)
 *   1 = critical error before the job could be loaded (DB not updated)
 *
 * The coordinator checks the exit code and re-queues the job if it receives 1.
 */
class QueueRunJobCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:run-job';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Execute a single job by ID (used internally by the coordinator).')
            ->addArgument('job-id', [
                'help' => 'ID of the job to execute.',
                'required' => true,
            ])
            ->addOption('worker-id', [
                'help' => 'Expected workerkey owner for the claimed job.',
                'required' => false,
            ])
            ->addOption('force', [
                'help' => 'Bypass claim ownership validation and run the row as-is.',
                'boolean' => true,
                'default' => false,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $jobId = (int) $args->getArgument('job-id');
        if ($jobId <= 0) {
            $io->error('Invalid job ID value.');
            return self::CODE_ERROR;
        }

        $workerId = $args->getOption('worker-id');
        $force = (bool) $args->getOption('force');

        // worker-id keeps manual invocations safe: by default the runner only
        // executes a row that is already claimed for the expected worker.
        $runner = new JobRunner();
        $exitCode = $runner->run(
            $jobId,
            is_string($workerId) && $workerId !== '' ? $workerId : null,
            $force
        );

        return $exitCode === 0 ? self::CODE_SUCCESS : self::CODE_ERROR;
    }
}
