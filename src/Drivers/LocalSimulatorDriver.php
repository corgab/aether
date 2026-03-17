<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Bridge\PythonBridge;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;

/**
 * Quantum driver for the local Braket simulator.
 */
class LocalSimulatorDriver implements QuantumDevice
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
     * Execute the circuit on the local simulator and return the measurement counts.
     *
     * @param  CircuitBuilder  $circuit
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $payload = array_merge($circuit->toArray(), [
            'driver' => 'local',
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('circuit.py', $payload, $this->config);

        return new CircuitResult($response['counts']);
    }

    /**
     * Generate random entropy using the local simulator and return raw bytes.
     *
     * @param  int  $bits
     * @return string
     */
    public function generateEntropy(int $bits): string
    {
        $payload = [
            'bits' => $bits,
            'driver' => 'local',
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('entropy.py', $payload, $this->config);

        return $this->bitstringToBytes($response['bits']);
    }

    /**
     * Convert a binary string (e.g. "10110011") into raw bytes.
     *
     * @param  string  $bitstring
     * @return string
     */
    private function bitstringToBytes(string $bitstring): string
    {
        $bytes = '';

        foreach (str_split($bitstring, 8) as $chunk) {
            $bytes .= chr((int) bindec($chunk));
        }

        return $bytes;
    }
}
