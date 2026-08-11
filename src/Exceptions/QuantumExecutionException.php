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

    /**
     * Create an exception when a Python script returns a response that does
     * not match the shape expected by the driver (missing key, wrong type,
     * or otherwise unusable).
     */
    public static function malformedResponse(string $script, string $reason): self
    {
        return new self(
            "Python script [{$script}] returned a malformed response: {$reason}"
        );
    }

    /**
     * Create an exception for drivers that do not support asynchronous execution.
     */
    public static function asynchronousUnsupported(string $driver): self
    {
        return new self(
            "Driver [{$driver}] does not support asynchronous execution. Drivers implementing Aether\\Contracts\\AsynchronousDevice (currently 'local' and 'aws') support dispatch() via SubmitQuantumCircuit."
        );
    }

    /**
     * Create an exception when a polling job exhausts its allotted number of
     * attempts without the task reaching a terminal state.
     */
    public static function pollingExhausted(string $taskArn, int $attempts): self
    {
        return new self(
            "Quantum task [{$taskArn}] did not reach a terminal state after {$attempts} poll attempts. Inspect the task in the AWS Braket console."
        );
    }
}
