<?php

declare(strict_types=1);

namespace SpawnQueue\Test\Stub;

/**
 * A legacy-task stub that intentionally has no run() method.
 * Used to verify LegacyTaskAdapter treats duck-typing failures as retryable.
 */
class NoRunTask
{
    // no run() method — calling $task->run() will throw \Error
}
