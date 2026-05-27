<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Handler;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Exception\NonRetryableJobException;
use SpawnQueue\Exception\RetryableJobException;
use SpawnQueue\Handler\EmailJobHandler;
use SpawnQueue\Test\Stub\ActionMailer;
use SpawnQueue\Test\Stub\FakeMailer;
use SpawnQueue\Test\Stub\ThrowingMailer;
use SpawnQueue\ValueObject\JobData;

class EmailJobHandlerTest extends TestCase
{
    // ── Static contract ───────────────────────────────────────────────────────

    public function testQueueReturnsEmails(): void
    {
        $this->assertSame('emails', EmailJobHandler::queue());
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function testMissingSettingsFailsWithoutRetry(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(NonRetryableJobException::class);
        $handler->handle($this->makeJob([]));
    }

    public function testInvalidHeadersFailsWithoutRetry(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(NonRetryableJobException::class);
        $handler->handle($this->makeJob([
            'settings' => ['to' => 'user@example.com', 'subject' => 'Test'],
            'headers' => 'invalid',
        ]));
    }

    public function testInvalidVarsThrowsNonRetryable(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(NonRetryableJobException::class);
        $handler->handle($this->makeJob([
            'settings' => ['to' => 'user@example.com', 'subject' => 'Test'],
            'vars'     => 'not-an-array',
        ]));
    }

    public function testEmptySettingsArrayThrowsNonRetryable(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(NonRetryableJobException::class);
        $handler->handle($this->makeJob(['settings' => []]));
    }

    public function testValidSettingsSendsWithFakeMailer(): void
    {
        $handler = new EmailJobHandler();

        $result = $handler->handle($this->makeJob([
            'mailer_class' => FakeMailer::class,
            'settings'     => [
                'from'    => 'sender@example.com',
                'to'      => 'recipient@example.com',
                'subject' => 'Hello',
            ],
        ]));

        $this->assertTrue($result->success);
    }

    public function testInvalidMailerClassThrowsNonRetryable(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(NonRetryableJobException::class);
        $handler->handle($this->makeJob([
            'mailer_class' => 'NonExistent\\Mailer\\Class',
            'settings'     => ['to' => 'x@example.com', 'subject' => 'Hi'],
        ]));
    }

    public function testActionCallsDoSendMethod(): void
    {
        ActionMailer::$actionWasCalled = false;

        $handler = new EmailJobHandler();
        $result  = $handler->handle($this->makeJob([
            'mailer_class' => ActionMailer::class,
            'settings'     => ['from' => 'sender@example.com'],
            'action'       => 'doSend',
        ]));

        $this->assertTrue(ActionMailer::$actionWasCalled);
        $this->assertTrue($result->success);
    }

    public function testTransportExceptionBecomesRetryable(): void
    {
        $handler = new EmailJobHandler();

        $this->expectException(RetryableJobException::class);
        $handler->handle($this->makeJob([
            'mailer_class' => ThrowingMailer::class,
            'settings'     => [
                'from'    => 'sender@example.com',
                'to'      => 'recipient@example.com',
                'subject' => 'Fail',
            ],
        ]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeJob(array $payload): JobData
    {
        return JobData::fromRow([
            'id'              => '20',
            'queue'           => 'emails',
            'job_task'        => EmailJobHandler::class,
            'data'            => json_encode($payload),
            'failed'          => '0',
            'max_attempts'    => '5',
            'priority'        => '5',
            'workerkey'       => null,
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ]);
    }
}
