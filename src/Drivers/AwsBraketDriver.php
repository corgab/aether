<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\PythonExecutor;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskSnapshot;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 */
class AwsBraketDriver extends AbstractQuantumDriver implements AsynchronousDevice, EstimatesCost
{
    /**
     * Normalizes the bucket once, before the (readonly) config array is ever
     * stored, so every path — synchronous or asynchronous — sees the same
     * shape. Doing this later, by writing into $this->config from a method,
     * cannot work: $config is declared readonly on AbstractQuantumDriver, and
     * only that class's own constructor may ever assign it.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(PythonExecutor $bridge, array $config)
    {
        parent::__construct($bridge, self::normalizeBucket($config));
    }

    protected function driverName(): string
    {
        return 'aws';
    }

    /**
     * The S3 bucket is optional: without one the Braket SDK writes results to
     * its default bucket, amazon-braket-<region>-<account>, which it creates on
     * first use (so the credentials need s3:CreateBucket).
     *
     * @return list<string>
     */
    protected function requiredConfig(): array
    {
        return ['region', 'device_arn'];
    }

    /**
     * Trim the configured bucket, or drop the key entirely once it is blank
     * (absent, null, or whitespace-only — what env() yields for
     * `AETHER_S3_BUCKET=`), so the Python side's `"bucket" not in config`
     * check is the single place that decides whether one was given.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeBucket(array $config): array
    {
        $bucket = trim((string) ($config['bucket'] ?? ''));

        if ($bucket === '') {
            unset($config['bucket']);
        } else {
            $config['bucket'] = $bucket;
        }

        return $config;
    }

    protected function beforeExecution(): void
    {
        if (($this->config['synchronous_safe'] ?? true) === false) {
            throw QuantumExecutionException::synchronousUnsafe('aws');
        }
    }

    /**
     * Add the cost ceiling to the shared admission checks, so it holds on
     * ->run(), Quantum::batch() and ->dispatch() alike.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     */
    protected function validateCircuits(array $circuits): void
    {
        parent::validateCircuits($circuits);

        $this->assertWithinCostCeiling($circuits);
    }

    /**
     * @throws InvalidCircuitException
     */
    public function submitCircuit(CircuitBuilder $circuit): string
    {
        return $this->submitTask($circuit);
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        return $this->pollTask($taskArn);
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
     * Guard against a run — one circuit, or a whole batch — whose estimated
     * cost exceeds the driver's configured `max_cost_per_run` ceiling.
     *
     * A blank `max_cost_per_run` (absent, null, or an empty string — what
     * env() yields for `AETHER_AWS_MAX_COST=`) means unlimited — the default,
     * so existing configs keep working unchanged. A configured ceiling with
     * no `pricing` rates would silently never trip (every estimate would be
     * 0.00), so that combination fails fast as a misconfiguration instead.
     * Shots are only summed across $circuits once a ceiling is actually
     * configured, mirroring the qubit-ceiling guard's lazy evaluation.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     * @throws InvalidDriverConfigException
     */
    private function assertWithinCostCeiling(array $circuits): void
    {
        $ceiling = $this->config['max_cost_per_run'] ?? null;

        if (blank($ceiling)) {
            return;
        }

        $pricing = $this->config['pricing'] ?? [];
        $missing = array_filter(
            ['pricing.per_task', 'pricing.per_shot'],
            static fn (string $key): bool => blank($pricing[substr($key, strlen('pricing.'))] ?? null),
        );

        if ($missing !== []) {
            throw InvalidDriverConfigException::missingKeys($this->driverName(), array_values($missing));
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
