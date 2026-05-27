<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\Utility;

use PHPUnit\Framework\TestCase;
use SpawnQueue\Utility\ProcessChecker;

class ProcessCheckerTest extends TestCase
{
    public function testCurrentProcessIsAlive(): void
    {
        $this->assertTrue(ProcessChecker::isAlive(getmypid()));
    }

    public function testNonExistentPidIsNotAlive(): void
    {
        // PHP_INT_MAX is guaranteed not to be a running process PID.
        $this->assertFalse(ProcessChecker::isAlive(PHP_INT_MAX));
    }

    public function testNeverThrowsForUnexpectedInputs(): void
    {
        // Verifies that the OS-call layer (tasklist / posix_kill) is wrapped
        // safely and never leaks exceptions for unusual PID values.
        $this->assertIsBool(ProcessChecker::isAlive(0));
        $this->assertIsBool(ProcessChecker::isAlive(-1));
        $this->assertIsBool(ProcessChecker::isAlive(PHP_INT_MAX));
    }
}
