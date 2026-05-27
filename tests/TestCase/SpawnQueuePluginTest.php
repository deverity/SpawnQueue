<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Core\PluginApplicationInterface;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueCleanupCommand;
use SpawnQueue\Command\QueueRequeueStuckCommand;
use SpawnQueue\Command\QueueRetryFailedCommand;
use SpawnQueue\Command\QueueRunJobCommand;
use SpawnQueue\Command\QueueStatsCommand;
use SpawnQueue\Command\QueueWorkAllCommand;
use SpawnQueue\Command\QueueWorkCommand;
use SpawnQueue\SpawnQueuePlugin;

class SpawnQueuePluginTest extends TestCase
{
    private array $savedConfig;
    private bool  $pluginWasRegistered;

    protected function setUp(): void
    {
        $this->savedConfig         = Configure::read('SpawnQueue') ?? [];
        $this->pluginWasRegistered = Plugin::getCollection()->has('SpawnQueue');

        if (!$this->pluginWasRegistered) {
            Plugin::getCollection()->add(new BasePlugin([
                'name' => 'SpawnQueue',
                'path' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR,
            ]));
        }
    }

    protected function tearDown(): void
    {
        if ($this->savedConfig) {
            Configure::write('SpawnQueue', $this->savedConfig);
        } else {
            Configure::delete('SpawnQueue');
        }
    }

    // ── bootstrap ─────────────────────────────────────────────────────────────

    public function testBootstrapLoadsDefaultConfigWhenKeyMissing(): void
    {
        Configure::delete('SpawnQueue');

        $app = $this->createMock(PluginApplicationInterface::class);
        (new SpawnQueuePlugin())->bootstrap($app);

        $this->assertTrue(Configure::check('SpawnQueue'));
        $this->assertSame(1, Configure::read('SpawnQueue.poll_interval'));
    }

    public function testBootstrapDoesNotOverwriteExistingConfig(): void
    {
        Configure::write('SpawnQueue.poll_interval', 99);

        $app = $this->createMock(PluginApplicationInterface::class);
        (new SpawnQueuePlugin())->bootstrap($app);

        $this->assertSame(99, Configure::read('SpawnQueue.poll_interval'));
    }

    // ── console ───────────────────────────────────────────────────────────────

    public function testConsoleRegistersAllExpectedCommands(): void
    {
        $collection = new CommandCollection();
        $result     = (new SpawnQueuePlugin())->console($collection);

        $this->assertSame($collection, $result);

        $expected = [
            'queue:work'          => QueueWorkCommand::class,
            'queue:work-all'      => QueueWorkAllCommand::class,
            'queue:run-job'       => QueueRunJobCommand::class,
            'queue:requeue-stuck' => QueueRequeueStuckCommand::class,
            'queue:cleanup'       => QueueCleanupCommand::class,
            'queue:stats'         => QueueStatsCommand::class,
            'queue:retry-failed'  => QueueRetryFailedCommand::class,
        ];

        foreach ($expected as $name => $class) {
            $this->assertTrue($collection->has($name), "Command '{$name}' must be registered");
            $this->assertSame($class, $collection->get($name));
        }
    }
}
