<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Handler;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Handler\JobHandlerInterface;
use SpawnQueue\ValueObject\JobData;
use SpawnQueue\Worker\JobResult;

class JobHandlerInterfaceTest extends TestCase
{
    /**
     * Documents the JobHandlerInterface contract:
     *   - queue() must return a non-empty string naming the target queue
     *   - handle() must return a JobResult
     *
     * Any class satisfying these requirements is a valid SpawnQueue handler.
     */
    public function testConcreteImplementationSatisfiesContract(): void
    {
        $handler = new class implements JobHandlerInterface {
            public static function queue(): string
            {
                return 'test-queue';
            }

            public function handle(JobData $job): JobResult
            {
                return JobResult::success();
            }
        };

        $this->assertSame('test-queue', $handler::queue());

        $job = $this->makeJobData();
        $result = $handler->handle($job);

        $this->assertInstanceOf(JobResult::class, $result);
        $this->assertTrue($result->success);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeJobData(): JobData
    {
        return JobData::fromRow([
            'id'              => 1,
            'queue'           => 'test-queue',
            'job_task'        => 'SpawnQueue\\Test\\TestCase\\Handler\\JobHandlerInterfaceTest',
            'data'            => '{}',
            'failed'          => 0,
            'max_attempts'    => 5,
            'priority'        => 5,
            'status'          => 'processing',
            'workerkey'       => 'test',
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ]);
    }
}
