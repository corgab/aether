<?php

declare(strict_types=1);

namespace Aether\Testing\Concerns;

use Aether\Circuit\CircuitBuilder;

trait RecordsActivity
{
    /**
     * Driver name reported on CircuitExecuted/EntropyGenerated when the fake
     * has no better one to use: no pinned name on the circuit and no driver
     * alias resolved through the manager yet (see resolvedAs()).
     */
    protected const FAKE_DRIVER = 'fake';

    /**
     * The driver alias most recently resolved through QuantumManager::driver()
     * while this fake was installed, so events report the same name the real
     * driver would have (Quantum::entropy('aws') reports 'aws', not 'fake').
     */
    protected ?string $resolvedDriver = null;

    /** @var CircuitBuilder[] */
    protected array $recordedCircuits = [];

    /** @var array<int, CircuitBuilder[]> */
    protected array $recordedBatches = [];

    /** @var int[] */
    protected array $recordedEntropy = [];

    /** @var CircuitBuilder[] */
    protected array $dispatchedCircuits = [];

    /** @var array<string, CircuitBuilder> Task ARN => the circuit it was submitted for. */
    protected array $tasksByArn = [];

    protected int $dispatchCounter = 0;

    /**
     * Remember which driver alias the manager resolved to this fake, so the
     * events it dispatches carry that name instead of the generic 'fake'.
     */
    public function resolvedAs(string $driver): static
    {
        $this->resolvedDriver = $driver;

        return $this;
    }

    /**
     * Return whether at least one circuit has been executed.
     */
    public function hasExecutedCircuits(): bool
    {
        return $this->recordedCircuits !== [];
    }

    /**
     * Return all recorded CircuitBuilder instances.
     *
     * @return CircuitBuilder[]
     */
    public function recordedCircuits(): array
    {
        return $this->recordedCircuits;
    }

    /**
     * Return whether at least one entropy generation has been recorded.
     */
    public function hasGeneratedEntropy(): bool
    {
        return $this->recordedEntropy !== [];
    }

    /**
     * Return all recorded bit-lengths passed to generateEntropy().
     *
     * @return int[]
     */
    public function recordedEntropy(): array
    {
        return $this->recordedEntropy;
    }

    /**
     * Return all CircuitBuilder instances submitted via submitCircuit().
     *
     * @return CircuitBuilder[]
     */
    public function dispatchedCircuits(): array
    {
        return $this->dispatchedCircuits;
    }

    /**
     * Return all recorded batches, each as the list of CircuitBuilder instances it contained.
     *
     * @return array<int, CircuitBuilder[]>
     */
    public function recordedBatches(): array
    {
        return $this->recordedBatches;
    }

    /**
     * The driver name to report on events: the circuit's pinned name, else
     * the alias the manager resolved to this fake, else the generic 'fake'.
     */
    protected function driverNameFor(?CircuitBuilder $circuit): string
    {
        return $circuit?->driverName() ?? $this->resolvedDriver ?? self::FAKE_DRIVER;
    }
}
