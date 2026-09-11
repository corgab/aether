<?php

declare(strict_types=1);

namespace Aether\Events;

use Aether\Tasks\TaskStatus;

/**
 * Fired when an asynchronously dispatched quantum circuit ends without a result.
 *
 * The counterpart of CircuitCompleted: the backend reported FAILED or
 * CANCELLED, the polling budget ran out, or the task completed without
 * measurement counts. The polling job dispatches it right before throwing,
 * so listeners can react without reading the failed_jobs table.
 */
final readonly class CircuitFailed
{
    /**
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}|string  $circuit
     * @param  TaskStatus  $status  The last status the backend reported: FAILED or CANCELLED,
     *                              the non-terminal status seen when the polling budget ran out,
     *                              or COMPLETED for a task that returned no counts.
     * @param  string  $reason  The message of the exception the polling job throws.
     */
    public function __construct(
        public string $driver,
        public array|string $circuit,
        public string $taskArn,
        public TaskStatus $status,
        public string $reason,
    ) {}
}
