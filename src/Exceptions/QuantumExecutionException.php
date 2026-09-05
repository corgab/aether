<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a quantum circuit execution fails.
 */
class QuantumExecutionException extends AetherException
{
    /**
     * Identifiers of the backend tasks the Python script had already
     * submitted when it failed or was killed.
     *
     * @var list<string>
     */
    private array $taskArns = [];

    /**
     * Create an exception from a Python subprocess error.
     *
     * Python scripts announce every task they create on stderr the moment it
     * exists, so a failure that happened after submission can name the tasks
     * left behind on the backend.
     *
     * @param  list<string>  $taskArns  Tasks submitted before the failure.
     */
    public static function fromPythonError(string $script, string $stderr, int $exitCode, array $taskArns = []): self
    {
        $message = "Python script [{$script}] failed with exit code {$exitCode}. Stderr: {$stderr}";

        if ($taskArns !== []) {
            $message .= ' Submitted task(s): '.implode(', ', $taskArns).
                '. The remote task may still be running; inspect or cancel it in the AWS Braket console.';
        }

        return (new self($message, $exitCode))->withTaskArns($taskArns);
    }

    /**
     * Create an exception for a Python subprocess killed by the bridge timeout.
     *
     * Killing the subprocess does not cancel work already submitted to the
     * backend, so any announced task identifiers are named in the message and
     * kept available through {@see taskArns()}.
     *
     * @param  list<string>  $taskArns  Tasks submitted before the kill.
     */
    public static function timedOut(string $script, int $timeout, array $taskArns = []): self
    {
        $message = "Python script [{$script}] timed out after {$timeout}s and was killed.";

        $message .= $taskArns !== []
            ? ' It had already submitted task(s) '.implode(', ', $taskArns).
                ', which keep running (and billing) on the backend: inspect or cancel them in the AWS Braket console, and prefer ->dispatch() for devices that queue.'
            : ' No task had been submitted yet.';

        return (new self($message))->withTaskArns($taskArns);
    }

    /**
     * Return the identifiers of the tasks submitted before this failure.
     *
     * @return list<string>
     */
    public function taskArns(): array
    {
        return $this->taskArns;
    }

    /**
     * Determine whether any task identifier was captured for this failure.
     */
    public function hasTaskArns(): bool
    {
        return $this->taskArns !== [];
    }

    /**
     * Attach the submitted task identifiers to this exception.
     *
     * @param  list<string>  $taskArns
     */
    private function withTaskArns(array $taskArns): static
    {
        $this->taskArns = $taskArns;

        return $this;
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

    /**
     * Create an exception for drivers that do not support batch execution.
     */
    public static function batchUnsupported(string $driver): self
    {
        return new self(
            "Driver [{$driver}] does not support batch execution. Implement Aether\Contracts\BatchableDevice to enable Quantum::batch()."
        );
    }

    /**
     * Create an exception for drivers that do not support cost estimation.
     */
    public static function costEstimationUnsupported(string $driver): self
    {
        return new self(
            "Driver [{$driver}] does not support cost estimation. Implement Aether\Contracts\EstimatesCost to enable CircuitBuilder::estimateCost()."
        );
    }
}
