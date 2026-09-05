<?php

declare(strict_types=1);

namespace Aether\Tests\Feature\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

/**
 * Test double for a driver that supports asynchronous execution.
 *
 * Records submitted circuits and checked task ARNs, and returns a
 * configurable snapshot so tests can drive the polling job through every
 * branch of its state machine.
 */
final class FakeAsynchronousDevice implements AsynchronousDevice, QuantumDevice
{
    /** @var CircuitBuilder[] */
    public array $submittedCircuits = [];

    /** @var string[] */
    public array $checkedTaskArns = [];

    public string $taskArnToReturn = 'arn:aws:braket:us-east-1:123456789012:quantum-task/fake';

    public TaskSnapshot $snapshotToReturn;

    public ?\Throwable $throwOnCheck = null;

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

    public function submitCircuit(CircuitBuilder $circuit): string
    {
        $this->submittedCircuits[] = $circuit;

        return $this->taskArnToReturn;
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        $this->checkedTaskArns[] = $taskArn;

        if ($this->throwOnCheck !== null) {
            throw $this->throwOnCheck;
        }

        return $this->snapshotToReturn;
    }
}
