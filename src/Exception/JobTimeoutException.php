<?php

declare(strict_types=1);

namespace SpawnQueue\Exception;

use RuntimeException;

/**
 * Internal exception used by the coordinator when a child process
 * exceeds its configured timeout.
 *
 * This is never thrown inside user-land handlers.
 */
class JobTimeoutException extends RuntimeException {}
