<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a Python subprocess exceeds the configured process_timeout.
 *
 * A distinct type so callers can tell a timeout apart from other execution
 * failures: retrying it would run the same work against the same limit, and
 * for a submission it may leave a task behind that the retry would duplicate.
 */
class PythonProcessTimedOutException extends QuantumExecutionException
{
    /**
     * Create an exception for a script killed after the configured number of seconds.
     */
    public static function afterSeconds(string $script, int $timeout): self
    {
        return new self(
            "Python script [{$script}] timed out after {$timeout}s. Raise process_timeout (AETHER_PROCESS_TIMEOUT) in config/aether.php if the circuit legitimately needs longer."
        );
    }
}
