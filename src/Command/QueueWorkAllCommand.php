<?php

declare(strict_types=1);

namespace SpawnQueue\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use SpawnQueue\Console\TuiLogger;
use SpawnQueue\Coordinator\SuperCoordinator;
use SpawnQueue\ValueObject\QueueConfig;

/**
 * Starts a single SuperCoordinator that manages every configured queue in one process.
 *
 * Queue list is read from Configure::read('SpawnQueue.queues').
 * Falls back to ['default'] when no queues are explicitly configured.
 *
 * Usage:
 *   php bin/cake queue:work-all
 *
 * Typically used for low-to-medium traffic deployments where a single
 * long-running process is simpler to manage than one Supervisor program per queue.
 *
 * For high-traffic or isolated queues, use queue:work <name> per queue instead.
 *
 * Typically managed by Supervisor or systemd:
 *
 *   [program:spawnqueue]
 *   command=php /var/www/app/bin/cake queue:work-all
 *   autostart=true
 *   autorestart=true
 *   stopwaitsecs=60
 */
class QueueWorkAllCommand extends Command
{
    public static function defaultName(): string
    {
        return 'queue:work-all';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Start a SuperCoordinator that manages all configured queues in a single process.')
            ->addOption('show', [
                'help'    => 'Output mode: lines (log lines only, default) or tui (live dashboard only). Overrides SpawnQueue.show_type config.',
                'default' => null,
                'choices' => ['lines', 'tui'],
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $showType = $args->getOption('show') ?? Configure::read('SpawnQueue.show_type') ?? 'lines';
        TuiLogger::setShowType((string) $showType);

        $queues = $this->resolveQueueNames();

        $configs = array_map(
            fn(string $queue) => QueueConfig::forQueue($queue),
            $queues
        );

        $super = new SuperCoordinator($configs);
        $super->run();

        return self::CODE_SUCCESS;
    }

    /**
     * Reads the configured queue list and returns an array of queue name strings.
     *
     * Supports two config shapes:
     *   - Associative: ['emails' => [...], 'default' => [...]]  → keys are names
     *   - Sequential:  ['emails', 'default']                    → values are names
     *
     * Falls back to ['default'] when nothing is configured.
     *
     * @return string[]
     */
    private function resolveQueueNames(): array
    {
        $queueConfig = Configure::read('SpawnQueue.queues');

        if (empty($queueConfig) || !is_array($queueConfig)) {
            return ['default'];
        }

        $first = array_key_first($queueConfig);

        // Associative array (queue name => settings) — use the keys.
        if (is_string($first)) {
            return array_keys($queueConfig);
        }

        // Sequential array of queue name strings.
        return array_values($queueConfig);
    }
}
