<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a quantum circuit execution fails.
 */
class QuantumExecutionException extends AetherException
{
    /**
     * Create an exception from a Python subprocess error.
     *
     * @param  string  $script
     * @param  string  $stderr
     * @param  int  $exitCode
     */
    public static function fromPythonError(string $script, string $stderr, int $exitCode): self
    {
        return new self(
            "Python script [{$script}] failed with exit code {$exitCode}. Stderr: {$stderr}",
            $exitCode
        );
    }

    /**
     * Create an exception for drivers that do not support synchronous execution.
     *
     * @param  string  $driver
     */
    public static function synchronousUnsafe(string $driver): self
    {
        return new self(
            "Driver [{$driver}] does not support synchronous execution. Use dispatch() or queue() instead."
        );
    }
}
