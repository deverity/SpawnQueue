<?php

declare(strict_types=1);

namespace SpawnQueue\Test\TestCase\ValueObject;

use PHPUnit\Framework\TestCase;
use SpawnQueue\ValueObject\JobData;

class JobDataTest extends TestCase
{
    // ── fromRow: new SpawnQueue row ───────────────────────────────────────────

    public function testFromRowMapsNewStyleColumns(): void
    {
        $row = [
            'id'              => '42',
            'queue'           => 'emails',
            'job_task'        => 'App\\Handler\\SendEmailHandler',
            'data'            => json_encode(['to' => 'foo@bar.com']),
            'failed'          => '2',
            'max_attempts'    => '5',
            'priority'        => '8',
            'workerkey'       => 'host:1234:emails',
            'pid'             => '9999',
            'failure_message' => 'previous error',
            'notbefore'       => '2026-04-01 10:00:00',
            'fetched'         => '2026-04-01 10:01:00',
        ];

        $job = JobData::fromRow($row);

        $this->assertSame(42, $job->id);
        $this->assertSame('emails', $job->queue);
        $this->assertSame('App\\Handler\\SendEmailHandler', $job->task);
        $this->assertSame(['to' => 'foo@bar.com'], $job->payload);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(5, $job->maxAttempts);
        $this->assertSame(8, $job->priority);
        $this->assertSame('host:1234:emails', $job->workerId);
        $this->assertSame(9999, $job->pid);
        $this->assertSame('previous error', $job->lastError);
        $this->assertSame('2026-04-01 10:00:00', $job->availableAt);
        $this->assertSame('2026-04-01 10:01:00', $job->reservedAt);
    }

    public function testEmptyQueueStringNormalizesToDefault(): void
    {
        $job = JobData::fromRow($this->baseRow(['queue' => '']));

        $this->assertSame('default', $job->queue);
    }

    // ── fromRow: legacy dereuromark row ──────────────────────────────────────

    public function testFromRowHandlesLegacyDereumarkRow(): void
    {
        // Old dereuromark rows have no 'queue' or 'max_attempts' columns.
        $row = [
            'id'              => '7',
            'job_task'        => 'App\\Queue\\Task\\OldTask',
            'data'            => serialize(['key' => 'value']),
            'failed'          => '1',
            'priority'        => '5',
            'workerkey'       => null,
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
            // 'queue' and 'max_attempts' intentionally missing
        ];

        $job = JobData::fromRow($row);

        $this->assertSame(7, $job->id);
        $this->assertSame('default', $job->queue, 'NULL queue must default to "default"');
        $this->assertSame(['key' => 'value'], $job->payload, 'PHP-serialized payload must be decoded');
        $this->assertSame(5, $job->maxAttempts, 'Missing max_attempts must default to 5');
        $this->assertNull($job->pid);
        $this->assertNull($job->lastError);
        $this->assertNull($job->availableAt);
        $this->assertNull($job->reservedAt);
    }

    // ── Payload decoding ──────────────────────────────────────────────────────

    public function testFromRowDecodesJsonPayload(): void
    {
        $payload = ['action' => 'send', 'ids' => [1, 2, 3]];
        $job     = JobData::fromRow($this->baseRow(['data' => json_encode($payload)]));

        $this->assertSame($payload, $job->payload);
    }

    public function testFromRowDecodesPhpSerializedPayload(): void
    {
        $payload = ['legacy' => true, 'items' => ['a', 'b']];
        $job     = JobData::fromRow($this->baseRow(['data' => serialize($payload)]));

        $this->assertSame($payload, $job->payload);
    }

    public function testFromRowDecodesNumericJsonArray(): void
    {
        $job = JobData::fromRow($this->baseRow(['data' => json_encode([10, 20, 30])]));

        $this->assertSame([10, 20, 30], $job->payload);
    }

    public function testFromRowReturnsEmptyArrayForSerializedObject(): void
    {
        $job = JobData::fromRow($this->baseRow(['data' => serialize(new \stdClass())]));

        $this->assertSame([], $job->payload, 'Serialized object is not an array — must return []');
    }

    public function testFromRowReturnsEmptyArrayForNullPayload(): void
    {
        $job = JobData::fromRow($this->baseRow(['data' => null]));

        $this->assertSame([], $job->payload);
    }

    public function testFromRowReturnsEmptyArrayForEmptyStringPayload(): void
    {
        $job = JobData::fromRow($this->baseRow(['data' => '']));

        $this->assertSame([], $job->payload);
    }

    public function testFromRowReturnsEmptyArrayForGarbagePayload(): void
    {
        $job = JobData::fromRow($this->baseRow(['data' => 'not-json-not-serialize']));

        $this->assertSame([], $job->payload);
    }

    // ── Date columns ─────────────────────────────────────────────────────────

    public function testNotbeforeAndFetchedAreMapped(): void
    {
        $job = JobData::fromRow($this->baseRow([
            'notbefore' => '2026-06-01 09:00:00',
            'fetched'   => '2026-06-01 09:01:00',
        ]));

        $this->assertSame('2026-06-01 09:00:00', $job->availableAt);
        $this->assertSame('2026-06-01 09:01:00', $job->reservedAt);
    }

    public function testExtraColumnsCreatedAndCompletedAreIgnoredSafely(): void
    {
        $row = array_merge($this->baseRow(), [
            'created'   => '2026-05-01 08:00:00',
            'completed' => '2026-05-01 08:05:00',
        ]);
        $job = JobData::fromRow($row);

        // Extra DB columns must not throw; core fields are unaffected.
        $this->assertSame(1, $job->id);
        $this->assertSame('default', $job->queue);
    }

    // ── Priority default ──────────────────────────────────────────────────────

    public function testPriorityDefaultsToFiveWhenColumnAbsent(): void
    {
        $row = $this->baseRow();
        unset($row['priority']);
        $job = JobData::fromRow($row);

        $this->assertSame(5, $job->priority);
    }

    // ── hasExhaustedAttempts ──────────────────────────────────────────────────

    public function testHasExhaustedAttemptsReturnsFalseWhenUnder(): void
    {
        $job = JobData::fromRow($this->baseRow(['failed' => '3', 'max_attempts' => '5']));

        $this->assertFalse($job->hasExhaustedAttempts());
    }

    public function testHasExhaustedAttemptsReturnsTrueWhenEqual(): void
    {
        $job = JobData::fromRow($this->baseRow(['failed' => '5', 'max_attempts' => '5']));

        $this->assertTrue($job->hasExhaustedAttempts());
    }

    public function testHasExhaustedAttemptsReturnsTrueWhenOver(): void
    {
        // Edge case: failed > max_attempts (should not happen but must be safe)
        $job = JobData::fromRow($this->baseRow(['failed' => '7', 'max_attempts' => '5']));

        $this->assertTrue($job->hasExhaustedAttempts());
    }

    public function testHasExhaustedAttemptsReturnsFalseOnFirstAttempt(): void
    {
        $job = JobData::fromRow($this->baseRow(['failed' => '1', 'max_attempts' => '5']));

        $this->assertFalse($job->hasExhaustedAttempts());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'id'              => '1',
            'queue'           => 'default',
            'job_task'        => 'App\\Handler\\TestHandler',
            'data'            => json_encode([]),
            'failed'          => '0',
            'max_attempts'    => '5',
            'priority'        => '5',
            'workerkey'       => null,
            'pid'             => null,
            'failure_message' => null,
            'notbefore'       => null,
            'fetched'         => null,
        ], $overrides);
    }
}
