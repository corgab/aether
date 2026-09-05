<?php

declare(strict_types=1);

namespace Aether\Tests\Feature\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Contracts\ValidatesDispatch;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Throwable;

/**
 * Test double for a driver that supports asynchronous execution.
 *
 * Records submitted circuits and checked task ARNs, and returns a
 * configurable snapshot so tests can drive the polling job through every
 * branch of its state machine.
 */
final class FakeAsynchronousDevice implements AsynchronousDevice, QuantumDevice, ValidatesDispatch
{
    /** @var CircuitBuilder[] */
    public array $submittedCircuits = [];

    /** @var string[] */
    public array $checkedTaskArns = [];

    public string $taskArnToReturn = 'arn:aws:braket:us-east-1:123456789012:quantum-task/fake';

    public TaskSnapshot $snapshotToReturn;

    /** Throw this from submitCircuit() instead of returning an ARN. */
    public ?Throwable $throwOnSubmit = null;

    /** Throw this from validateDispatch(). */
    public ?Throwable $throwOnValidate = null;

    /** The queue connections validateDispatch() was called with. */
    public array $validatedConnections = [];

    public function __construct()
    {
        $this->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, ['00' => 5, '11' => 5]);
    }

    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        return new CircuitResult([]);
    }

    public function generateEntropy(int $bits): string
    {
        return str_repeat("\x00", (int) ceil($bits / 8));
    }

    public function validateDispatch(?string $queueConnection = null): void
    {
        $this->validatedConnections[] = $queueConnection;

        if ($this->throwOnValidate !== null) {
            throw $this->throwOnValidate;
        }
    }

    public function submitCircuit(CircuitBuilder $circuit): string
    {
        if ($this->throwOnSubmit !== null) {
            throw $this->throwOnSubmit;
        }

        $this->submittedCircuits[] = $circuit;

        return $this->taskArnToReturn;
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        $this->checkedTaskArns[] = $taskArn;

        return $this->snapshotToReturn;
    }
}
