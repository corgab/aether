<?php

declare(strict_types=1);

namespace Aether\Tests\Fixtures;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Drivers\AbstractQuantumDriver;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

/**
 * Minimal custom driver used by CustomProviderTest.
 *
 * Mirrors the shape a package consumer would write when pairing a driver
 * registered through Quantum::extend() with a "python_provider" module.
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
        $this->assertConfigured();

        $payload = array_merge($circuit->toArray(), [
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('submit.py', $payload, $this->config);

        $taskArn = $response['task_arn'] ?? null;

        if (! is_string($taskArn) || trim($taskArn) === '') {
            throw QuantumExecutionException::malformedResponse(
                'submit.py',
                'expected the "task_arn" key to be present and hold a non-empty string.'
            );
        }

        return $taskArn;
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        $this->assertConfigured();

        $payload = [
            'task_arn' => $taskArn,
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('check.py', $payload, $this->config);

        $status = $response['status'] ?? null;

        if (! is_string($status) || TaskStatus::tryFrom($status) === null) {
            throw QuantumExecutionException::malformedResponse(
                'check.py',
                'expected the "status" key to be present and hold a valid task status value.'
            );
        }

        return TaskSnapshot::fromResponse($response);
    }
}
