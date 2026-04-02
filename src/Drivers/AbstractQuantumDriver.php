<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;

/**
 * Base driver with shared circuit execution and entropy generation logic.
 */
abstract class AbstractQuantumDriver implements QuantumDevice
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly PythonExecutor $bridge,
        protected readonly array $config,
    ) {}

    /**
     * Return the driver identifier passed to Python scripts.
     */
    abstract protected function driverName(): string;

    /**
     * Hook called before every circuit execution or entropy generation.
     */
    protected function beforeExecution(): void {}

    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->beforeExecution();

        $payload = array_merge($circuit->toArray(), [
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('circuit.py', $payload, $this->config);

        return new CircuitResult($response['counts']);
    }

    public function generateEntropy(int $bits): string
    {
        $this->beforeExecution();

        $payload = [
            'bits' => $bits,
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('entropy.py', $payload, $this->config);

        return $this->bridge->bitstringToBytes($response['bits']);
    }
}
