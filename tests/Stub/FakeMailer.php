<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

use Cake\Mailer\Mailer;

/**
 * Test double for EmailJobHandler tests.
 * Overrides deliver() so no real email is sent and no transport is needed.
 */
class FakeMailer extends Mailer
{
    public array $deliveredContents = [];

    public function deliver(string $content = ''): array
    {
        $this->deliveredContents[] = $content;

        return ['headers' => [], 'message' => []];
    }
}
