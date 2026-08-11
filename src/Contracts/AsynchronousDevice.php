<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Circuit\CircuitBuilder;
use Aether\Tasks\TaskSnapshot;

/**
 * Contract for quantum backends that support asynchronous task submission.
 *
 * Asynchronous execution submits the circuit without waiting for the result,
 * returning a task identifier (ARN) that can be polled until the task reaches
 * a terminal state.
 */
interface AsynchronousDevice
{
    /**
     * Submit the circuit for asynchronous execution and return the task ARN.
     */
    public function submitCircuit(CircuitBuilder $circuit): string;

    /**
     * Return a point-in-time snapshot of the given task's status and, once
     * completed, its measurement counts.
     */
    public function checkTask(string $taskArn): TaskSnapshot;
}
