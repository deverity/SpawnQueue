<?php

declare(strict_types=1);

namespace SpawnQueue\ValueObject;

/**
 * Immutable representation of a queued_jobs row.
 *
 * Column mapping (queued_jobs → JobData):
 *   job_task         → task
 *   data             → payload   (decoded from JSON or PHP serialize)
 *   failed           → attempts  (incremented on each claim)
 *   notbefore        → availableAt
 *   fetched          → reservedAt
 *   completed        → finishedAt
 *   failure_message  → lastError
 *   workerkey        → workerId
 *   queue            → queue     (new SpawnQueue column; NULL = 'default')
 *   max_attempts     → maxAttempts (new SpawnQueue column)
 *   pid              → pid       (new SpawnQueue column)
 *   failed_at        → failedAt  (new SpawnQueue column)
 */
final class JobData
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $queue,
        public readonly string  $task,
        public readonly array   $payload,
        public readonly int     $attempts,
        public readonly int     $maxAttempts,
        public readonly int     $priority,
        public readonly string  $workerId,
        public readonly ?int    $pid,
        public readonly ?string $lastError,
        public readonly ?string $availableAt,
        public readonly ?string $reservedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            queue:       (isset($row['queue']) && $row['queue'] !== '') ? $row['queue'] : 'default',
            task:        $row['job_task'],
            payload:     self::decodePayload($row['data'] ?? null),
            attempts:    (int) ($row['failed'] ?? 0),
            maxAttempts: (int) ($row['max_attempts'] ?? 5),
            priority:    (int) ($row['priority'] ?? 5),
            workerId:    $row['workerkey'] ?? '',
            pid:         isset($row['pid']) && $row['pid'] !== null ? (int) $row['pid'] : null,
            lastError:   $row['failure_message'] ?? null,
            availableAt: $row['notbefore'] ?? null,
            reservedAt:  $row['fetched'] ?? null,
        );
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    private static function decodePayload(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        // Try JSON first (SpawnQueue native format).
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // Fallback: PHP serialize (dereuromark/cakephp-queue legacy format).
        $unserialized = @unserialize($raw);
        if (is_array($unserialized)) {
            return $unserialized;
        }

        return [];
    }
}
