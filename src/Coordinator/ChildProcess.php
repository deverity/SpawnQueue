<?php

declare(strict_types=1);

namespace SpawnQueue\Coordinator;

/**
 * Value object tracking a single spawned child process.
 */
final class ChildProcess
{
    /** @param resource $resource proc_open handle */
    public function __construct(
        public readonly int    $jobId,
        public readonly mixed  $resource,
        public readonly array  $pipes,
        public readonly int    $startedAt,
        public readonly int    $timeoutAt,
        public bool            $sigtermSent = false,
        public ?int            $sigtermSentAt = null,
    ) {}

    public function isTimedOut(): bool
    {
        return time() >= $this->timeoutAt;
    }

    public function isGracePeriodExpired(int $graceSeconds = 5): bool
    {
        return $this->sigtermSent
            && $this->sigtermSentAt !== null
            && (time() - $this->sigtermSentAt) >= $graceSeconds;
    }
}
