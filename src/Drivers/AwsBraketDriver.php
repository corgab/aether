<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 */
class AwsBraketDriver extends AbstractQuantumDriver implements AsynchronousDevice
{
    protected function driverName(): string
    {
        return 'aws';
    }

    /**
     * @return list<string>
     */
    protected function requiredConfig(): array
    {
        return ['region', 'device_arn'];
    }

    protected function beforeExecution(): void
    {
        if (($this->config['synchronous_safe'] ?? true) === false) {
            throw QuantumExecutionException::synchronousUnsafe('aws');
        }
    }

    public function submitCircuit(CircuitBuilder $circuit): string
    {
        // Config validation only — submitting a task and returning its ARN
        // never blocks on the QPU, so the synchronous-safety hook in
        // beforeExecution() must not run here.
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
        // Same reasoning as submitCircuit(): polling a task status never
        // blocks, so we skip beforeExecution() and only validate config.
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
