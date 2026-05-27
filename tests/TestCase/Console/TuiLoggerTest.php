<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SpawnQueue\Console\TuiLogger;
use SpawnQueue\ValueObject\QueueConfig;

class TuiLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('NO_COLOR=1');  // disable TTY/ANSI so dashboard is plain-text safe
        putenv('COLUMNS=80');  // consistent terminal width for all tests
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        putenv('NO_COLOR');
        putenv('COLUMNS');
        $this->resetStaticState();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    // ── Log methods ───────────────────────────────────────────────────────────

    public function testPublicLogMethodsDoNotThrowWithoutTty(): void
    {
        ob_start();
        TuiLogger::coordinator('emails', 'START running');
        TuiLogger::runner('STARTED job=42');
        TuiLogger::addLogLines(3);
        ob_end_clean();

        $this->addToAssertionCount(1);
    }

    // ── initDashboard ─────────────────────────────────────────────────────────

    public function testInitDashboardWithMultipleQueues(): void
    {
        $configs = [
            QueueConfig::forQueue('emails'),
            QueueConfig::forQueue('default'),
        ];

        ob_start();
        TuiLogger::initDashboard($configs);
        ob_end_clean();

        $ref = new ReflectionClass(TuiLogger::class);

        $statesProp = $ref->getProperty('queueStates');
        $statesProp->setAccessible(true);
        $states = $statesProp->getValue(null);

        $this->assertArrayHasKey('emails',  $states);
        $this->assertArrayHasKey('default', $states);
        $this->assertSame(4, $states['emails']['maxWorkers']);
        $this->assertSame(2, $states['default']['maxWorkers']);

        $orderProp = $ref->getProperty('queueOrder');
        $orderProp->setAccessible(true);
        $this->assertSame(['emails', 'default'], $orderProp->getValue(null));
    }

    // ── setPendingJobs ────────────────────────────────────────────────────────

    public function testSetPendingJobsRespectsMaxPendingShown(): void
    {
        ob_start();
        TuiLogger::initDashboard([QueueConfig::forQueue('emails')]);
        ob_end_clean();

        $jobs = [];
        for ($i = 1; $i <= 10; $i++) {
            $jobs[] = [
                'jobId'       => $i,
                'taskName'    => 'SendEmailHandler',
                'createdAt'   => '2026-01-01 00:00:00',
                'maxAttempts' => 5,
            ];
        }

        TuiLogger::setPendingJobs('emails', $jobs);

        $ref = new ReflectionClass(TuiLogger::class);
        $prop = $ref->getProperty('pendingJobs');
        $prop->setAccessible(true);
        $stored = $prop->getValue(null);

        $this->assertArrayHasKey('emails', $stored);
        $this->assertCount(5, $stored['emails'], 'MAX_PENDING_SHOWN = 5');
        $this->assertSame(1, $stored['emails'][0]['jobId']);
        $this->assertSame(5, $stored['emails'][4]['jobId']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resetStaticState(): void
    {
        $ref = new ReflectionClass(TuiLogger::class);

        $defaults = [
            'innerWidth'           => 52,
            'slotWidth'            => 24,
            'slotLabelWidth'       => 17,
            'colorEnabled'         => null,
            'dashboardReady'       => false,
            'dynamicSectionHeight' => 0,
            'linesAfterBanner'     => 0,
            'queueStates'          => [],
            'queueOrder'           => [],
            'pendingJobs'          => [],
            'recentTasks'          => [],
        ];

        foreach ($defaults as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }
}
