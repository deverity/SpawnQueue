<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use Cake\Mailer\Mailer;

/**
 * Test double for EmailJobHandler: deliver() always throws a RuntimeException.
 * Used to verify that transport failures are wrapped as RetryableJobException.
 */
class ThrowingMailer extends Mailer
{
    public function deliver(string $content = ''): array
    {
        throw new \RuntimeException('Simulated transport failure');
    }
}
