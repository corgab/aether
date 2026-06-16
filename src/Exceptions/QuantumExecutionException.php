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
     */
    public static function synchronousUnsafe(string $driver): self
    {
        return new self(
            "Driver [{$driver}] does not support synchronous execution. Use dispatch() or queue() instead."
        );
    }

    /**
     * Create an exception when rejection sampling cannot find an in-range
     * value within the allotted number of entropy batches.
     */
    public static function entropyExhausted(int $min, int $max, int $batches): self
    {
        return new self(
            "Failed to generate an in-range integer in [{$min}, {$max}] after {$batches} entropy batches. The entropy source may be degenerate."
        );
    }
}
