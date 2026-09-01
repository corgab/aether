<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 */
class AwsBraketDriver extends AbstractQuantumDriver implements AsynchronousDevice, EstimatesCost
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
        return ['region', 'device_arn', 'bucket'];
    }

    protected function beforeExecution(): void
    {
        if (($this->config['synchronous_safe'] ?? true) === false) {
            throw QuantumExecutionException::synchronousUnsafe('aws');
        }
    }

    /**
     * @throws InvalidCircuitException
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        // Validated ahead of the parent's own preflight() so a cost overrun
        // is rejected before *any* AWS call, including config validation
        // side effects — assertConfigured() is idempotent and re-runs there.
        $this->assertConfigured();
        $this->assertWithinCostCeiling([$circuit]);

        return parent::executeCircuit($circuit);
    }

    /**
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     */
    public function executeBatch(array $circuits): BatchResult
    {
        $this->assertConfigured();
        $this->assertWithinCostCeiling($circuits);

        return parent::executeBatch($circuits);
    }

    /**
     * @throws InvalidCircuitException
     */
    public function submitCircuit(CircuitBuilder $circuit): string
    {
        // Config validation and the cost guard only — submitting a task and
        // returning its ARN never blocks on the QPU, so the
        // synchronous-safety hook in beforeExecution() must not run here.
        $this->assertConfigured();
        $this->assertWithinCostCeiling([$circuit]);

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

    /**
     * Estimate the cost of running the given total number of shots across
     * the given number of tasks, using the driver's configured `pricing`
     * rates. No network call is made.
     */
    public function estimateCost(int $shots, int $tasks = 1): CostEstimate
    {
        $pricing = $this->config['pricing'] ?? [];

        $perTaskRate = (float) ($pricing['per_task'] ?? 0.0);
        $perShotRate = (float) ($pricing['per_shot'] ?? 0.0);
        $currency = (string) ($pricing['currency'] ?? 'USD');

        $taskCost = $perTaskRate * $tasks;
        $shotCost = $perShotRate * $shots;

        return new CostEstimate(
            amount: $taskCost + $shotCost,
            currency: $currency,
            shots: $shots,
            breakdown: [
                'per_task' => $taskCost,
                'per_shot' => $shotCost,
            ],
        );
    }

    /**
     * Guard against a circuit (or batch) whose estimated cost exceeds the
     * driver's configured `max_cost_per_task` ceiling.
     *
     * A null/absent `max_cost_per_task` means unlimited — the default, so
     * existing configs keep working unchanged. Shots are only summed across
     * $circuits once a ceiling is actually configured, mirroring the
     * qubit-ceiling guard's lazy evaluation.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     */
    private function assertWithinCostCeiling(array $circuits): void
    {
        $ceiling = $this->config['max_cost_per_task'] ?? null;

        if ($ceiling === null) {
            return;
        }

        $shots = array_sum(array_map(
            static fn (CircuitBuilder $c): int => $c->shotCount(),
            $circuits
        ));

        $estimate = $this->estimateCost($shots, count($circuits));

        if ($estimate->amount > (float) $ceiling) {
            throw InvalidCircuitException::costCeilingExceeded($estimate, (float) $ceiling);
        }
    }
}
