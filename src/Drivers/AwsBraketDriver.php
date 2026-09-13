<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Config\AwsDriverConfig;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\PythonExecutor;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskSnapshot;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 *
 * @extends AbstractQuantumDriver<AwsDriverConfig>
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

    /**
     * A Braket device ARN always has an empty region field (or us-east-1) and an
     * empty account-id field, so the resource segment "device/qpu/..." is
     * preceded by a colon, not a slash.
     */
    protected function isSynchronousSafeByDefault(): bool
    {
        $deviceArn = $this->config->deviceArn;

        if (is_string($deviceArn) && str_contains($deviceArn, 'device/qpu/')) {
            return false;
        }

        return true;
    }

    protected function driverName(): string
    {
        return 'aws';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function makeConfig(array $values): AwsDriverConfig
    {
        return new AwsDriverConfig($this->driverName(), $values);
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

    /**
     * Add the cost ceiling to the shared admission checks, so it holds on
     * ->run(), Quantum::batch(), ->dispatch() and Quantum::entropy() alike.
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
        $taskCost = ($this->config->perTaskRate ?? 0.0) * $tasks;
        $shotCost = ($this->config->perShotRate ?? 0.0) * $shots;

        return new CostEstimate(
            amount: $taskCost + $shotCost,
            currency: $this->config->currency,
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
     * A blank `max_cost_per_run` means unlimited (AwsDriverConfig leaves
     * $maxCostPerRun null) — the default, so existing configs keep working
     * unchanged. A configured ceiling with no `pricing` rates would silently
     * never trip (every estimate would be 0.00), so that combination fails
     * fast as a misconfiguration instead. Shots are only summed across
     * $circuits once a ceiling is actually configured, mirroring the
     * qubit-ceiling guard's lazy evaluation.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     * @throws InvalidDriverConfigException
     */
    private function assertWithinCostCeiling(array $circuits): void
    {
        $ceiling = $this->config->maxCostPerRun;

        if ($ceiling === null) {
            return;
        }

        $missing = $this->config->missingRates();

        if ($missing !== []) {
            throw InvalidDriverConfigException::missingKeys($this->driverName(), $missing);
        }

        $shots = array_sum(array_map(
            static fn (CircuitBuilder $c): int => $c->shotCount(),
            $circuits
        ));

        $estimate = $this->estimateCost($shots, count($circuits));

        if ($estimate->amount > $ceiling) {
            throw InvalidCircuitException::costCeilingExceeded($estimate, $ceiling);
        }
    }
}
