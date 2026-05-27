<?php

declare(strict_types=1);

namespace SpawnQueue\ValueObject;

final class BackoffSchedule
{
    private const DELAYS = [
        1 => 10,
        2 => 30,
        3 => 120,
        4 => 600,
        5 => 1800,
    ];

    public static function delayFor(int $attempt): int
    {
        return self::DELAYS[$attempt] ?? 1800;
    }
}
