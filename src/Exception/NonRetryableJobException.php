<?php

declare(strict_types=1);

namespace SpawnQueue\Exception;

use RuntimeException;

/**
 * Throw this inside a job handler to mark the job as permanently failed.
 * SpawnQueue will set status = 'failed' with no further retry attempt.
 *
 * Usage:
 *   throw new NonRetryableJobException('Invalid payload, cannot process');
 */
class NonRetryableJobException extends RuntimeException {}
