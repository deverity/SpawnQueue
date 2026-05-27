<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use SpawnQueue\Coordinator\QueueCoordinator;
use SpawnQueue\ValueObject\QueueConfig;

/**
 * Starts a coordinator for the given queue.
 *
 * Usage:
 *   php bin/cake queue:work default
 *   php bin/cake queue:work emails --max-workers=4
 *   php bin/cake queue:work imports --max-workers=1 --timeout=1800
 *
 * Typically managed by Supervisor or systemd:
 *
 *   [program:spawnqueue-emails]
 *   command=php /var/www/app/bin/cake queue:work emails --max-workers=4
 *   autostart=true
 *   autorestart=true
 *   stopwaitsecs=35
 */
class QueueWorkCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:work';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Start a SpawnQueue coordinator for the given queue.')
            ->addArgument('queue', [
                'help'     => 'Queue name to process (e.g. default, emails, imports).',
                'required' => true,
            ])
            ->addOption('max-workers', [
                'help'    => 'Maximum concurrent child processes. Overrides config.',
                'default' => null,
            ])
            ->addOption('timeout', [
                'help'    => 'Per-job timeout in seconds. Overrides config.',
                'default' => null,
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        // Config is already loaded by SpawnQueuePlugin::bootstrap().
        // Do NOT re-load here — it would overwrite user overrides applied after bootstrap.

        $queue  = (string) $args->getArgument('queue');
        $config = QueueConfig::forQueue($queue);

        // CLI overrides
        if ($args->getOption('max-workers') !== null) {
            $config = $config->with(['maxWorkers' => (int) $args->getOption('max-workers')]);
        }

        if ($args->getOption('timeout') !== null) {
            $config = $config->with(['timeout' => (int) $args->getOption('timeout')]);
        }

        $coordinator = new QueueCoordinator($config);
        $coordinator->run();

        return self::CODE_SUCCESS;
    }
}
