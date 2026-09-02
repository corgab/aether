<?php

declare(strict_types=1);

namespace Aether\Tests\Fixtures;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Drivers\AbstractQuantumDriver;
use Aether\Tasks\TaskSnapshot;

/**
 * Minimal custom driver used by CustomProviderTest.
 *
 * Mirrors the shape a package consumer would write when pairing a driver
 * registered through Quantum::extend() with a "python_provider" module: the
 * base class already ships the submit.py / check.py round trips as
 * submitTask() and pollTask(), so exposing the AsynchronousDevice contract is
 * a matter of delegating to them.
 */
final class CustomProviderDriver extends AbstractQuantumDriver implements AsynchronousDevice
{
    protected function driverName(): string
    {
        return 'custom';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfig(): array
    {
        return ['python_provider'];
    }

    public function submitCircuit(CircuitBuilder $circuit): string
    {
        return $this->submitTask($circuit);
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        return $this->pollTask($taskArn);
    }
}
