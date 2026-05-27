<?php

declare(strict_types=1);

namespace SpawnQueue;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use SpawnQueue\Command\QueueCleanupCommand;
use SpawnQueue\Command\QueueRequeueStuckCommand;
use SpawnQueue\Command\QueueRetryFailedCommand;
use SpawnQueue\Command\QueueRunJobCommand;
use SpawnQueue\Command\QueueStatsCommand;
use SpawnQueue\Command\QueueWorkAllCommand;
use SpawnQueue\Command\QueueWorkCommand;

class SpawnQueuePlugin extends BasePlugin
{
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        // Load default config only if the key is not already set.
        if (!Configure::check('SpawnQueue')) {
            Configure::load('SpawnQueue.spawn_queue', 'default', false);
        }
    }

    /**
     * Register all queue commands under the queue: namespace so they can be
     * invoked as:  php bin/cake queue:work emails --max-workers=4
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('queue:work',           QueueWorkCommand::class);
        $commands->add('queue:work-all',       QueueWorkAllCommand::class);
        $commands->add('queue:run-job',        QueueRunJobCommand::class);
        $commands->add('queue:requeue-stuck',  QueueRequeueStuckCommand::class);
        $commands->add('queue:cleanup',        QueueCleanupCommand::class);
        $commands->add('queue:stats',          QueueStatsCommand::class);
        $commands->add('queue:retry-failed',   QueueRetryFailedCommand::class);

        return $commands;
    }
}
