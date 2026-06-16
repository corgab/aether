<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidDriverConfigException;
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
     * Config keys that must be present and non-empty before the driver runs.
     *
     * Override in concrete drivers that talk to a remote service so that a
     * misconfiguration fails fast here, instead of the Python layer silently
     * falling back to its own defaults.
     *
     * @return list<string>
     */
    protected function requiredConfig(): array
    {
        return [];
    }

    /**
     * Hook for driver-specific pre-flight logic (e.g. safety checks).
     *
     * Runs before every circuit execution and entropy generation, after the
     * required-config check. Default is a no-op; overrides need no parent call.
     */
    protected function beforeExecution(): void {}

    /**
     * Run the mandatory pre-flight steps before spawning any Python subprocess.
     *
     * The config check lives here rather than in beforeExecution() so a driver
     * overriding the hook cannot silently skip validation by forgetting to call
     * the parent implementation.
     */
    private function preflight(): void
    {
        $this->assertConfigured();
        $this->beforeExecution();
    }

    /**
     * Ensure every required config key is present and non-empty, failing fast
     * with a clear message before any Python subprocess is spawned.
     */
    private function assertConfigured(): void
    {
        $missing = [];

        foreach ($this->requiredConfig() as $key) {
            $value = $this->config[$key] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw InvalidDriverConfigException::missingKeys($this->driverName(), $missing);
        }
    }

    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->preflight();

        $payload = array_merge($circuit->toArray(), [
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('circuit.py', $payload, $this->config);

        return new CircuitResult($response['counts']);
    }

    public function generateEntropy(int $bits): string
    {
        $this->preflight();

        $qubits = (int) ($this->config['entropy_qubits'] ?? 16);
        $shots = (int) ceil($bits / $qubits);

        $payload = [
            'qubits' => $qubits,
            'shots' => $shots,
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('entropy.py', $payload, $this->config);

        $bitstring = substr($response['bits'], 0, $bits);

        return $this->bridge->bitstringToBytes($bitstring);
    }
}
