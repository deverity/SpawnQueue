<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Worker;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Worker\JobRunner;

class JobRunnerTest extends TestCase
{
    public function testResolveHandlerClassNameSupportsFullyQualifiedHandler(): void
    {
        $runner = new JobRunner();
        $method = new \ReflectionMethod($runner, 'resolveHandlerClassName');
        $method->setAccessible(true);

        $resolved = $method->invoke($runner, 'SpawnQueue\\Test\\Stub\\NewStyleHandler');

        $this->assertSame('SpawnQueue\\Test\\Stub\\NewStyleHandler', $resolved);
    }

    public function testResolveHandlerClassNameSupportsLegacyShortTaskName(): void
    {
        $runner = new JobRunner();
        $method = new \ReflectionMethod($runner, 'resolveHandlerClassName');
        $method->setAccessible(true);

        $resolved = $method->invoke($runner, 'DocumentosDigitais');

        $this->assertSame('App\\Queue\\Task\\DocumentosDigitaisTask', $resolved);
    }

    public function testResolveHandlerClassNameSupportsLegacyPluginDotNotation(): void
    {
        $runner = new JobRunner();
        $method = new \ReflectionMethod($runner, 'resolveHandlerClassName');
        $method->setAccessible(true);

        $resolved = $method->invoke($runner, 'Queue.Email');

        $this->assertSame('SpawnQueue\\Handler\\EmailJobHandler', $resolved);
    }

    public function testResolveHandlerClassNameReturnsNullForUnknownTask(): void
    {
        $runner = new JobRunner();
        $method = new \ReflectionMethod($runner, 'resolveHandlerClassName');
        $method->setAccessible(true);

        $resolved = $method->invoke($runner, 'TaskThatDoesNotExistAnywhere');

        $this->assertNull($resolved);
    }
}
