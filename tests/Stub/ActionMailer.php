<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use Cake\Mailer\Mailer;

/**
 * Test double for EmailJobHandler action tests.
 * doSend() sets a static flag that tests can inspect to confirm the action was called.
 * deliver() is overridden so no real transport is needed.
 */
class ActionMailer extends Mailer
{
    public static bool $actionWasCalled = false;

    public function doSend(): void
    {
        self::$actionWasCalled = true;
        $this->setTo('recipient@example.com')
             ->setSubject('Action Test');
    }

    public function deliver(string $content = ''): array
    {
        return ['headers' => [], 'message' => []];
    }
}
