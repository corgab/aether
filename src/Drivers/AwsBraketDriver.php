<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Bridge\PythonBridge;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;

/**
 * Quantum driver for AWS Braket QPU and managed simulators.
 */
class AwsBraketDriver implements QuantumDevice
{
    /**
     * @param  PythonBridge  $bridge
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly PythonBridge $bridge,
        private readonly array $config,
    ) {}

    /**
     * Execute the circuit on AWS Braket and return the measurement counts.
     *
     * @param  CircuitBuilder  $circuit
     *
     * @throws QuantumExecutionException
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->ensureSynchronousSafe();

        $payload = array_merge($circuit->toArray(), [
            'driver' => 'aws',
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('circuit.py', $payload, $this->config);

        return new CircuitResult($response['counts']);
    }

    /**
     * Generate random entropy via AWS Braket and return raw bytes.
     *
     * @param  int  $bits
     * @return string
     *
     * @throws QuantumExecutionException
     */
    public function generateEntropy(int $bits): string
    {
        $this->ensureSynchronousSafe();

        $payload = [
            'bits' => $bits,
            'driver' => 'aws',
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('entropy.py', $payload, $this->config);

        return $this->bridge->bitstringToBytes($response['bits']);
    }

    /**
     * Assert that this driver may be called synchronously.
     *
     * @throws QuantumExecutionException
     */
    private function ensureSynchronousSafe(): void
    {
        if (($this->config['synchronous_safe'] ?? true) === false) {
            throw QuantumExecutionException::synchronousUnsafe('aws');
        }
    }
}
