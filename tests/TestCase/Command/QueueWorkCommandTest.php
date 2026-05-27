<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Command;

use Cake\Console\ConsoleOptionParser;
use PHPUnit\Framework\TestCase;
use SpawnQueue\Command\QueueWorkCommand;

class QueueWorkCommandTest extends TestCase
{
    public function testOptionParserDefinesQueueMaxWorkersAndTimeout(): void
    {
        $parser = (new QueueWorkCommand())->buildOptionParser(
            new ConsoleOptionParser('queue:work')
        );

        $this->assertArrayHasKey('queue', $parser->arguments(), 'queue positional argument must be defined');
        $this->assertArrayHasKey('max-workers', $parser->options(), '--max-workers option must be defined');
        $this->assertArrayHasKey('timeout', $parser->options(), '--timeout option must be defined');
    }
}
