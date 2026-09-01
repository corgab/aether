<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\BatchableDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;

/**
 * Base driver with shared circuit execution and entropy generation logic.
 */
abstract class AbstractQuantumDriver implements BatchableDevice, QuantumDevice
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
     * Run the mandatory pre-flight steps before spawning a *synchronous*
     * Python subprocess (executeCircuit()/generateEntropy()).
     *
     * The config check lives in assertConfigured() rather than in
     * beforeExecution() so a driver overriding the hook cannot silently skip
     * validation by forgetting to call the parent implementation.
     *
     * Asynchronous methods (submitCircuit()/checkTask()) must NOT go through
     * this method: submitting a task or polling it never blocks on the QPU,
     * so the synchronous-safety hook (e.g. AwsBraketDriver::beforeExecution())
     * must not fire for them. They call assertConfigured() directly instead —
     * see its docblock for why that still enforces validation.
     */
    private function preflight(): void
    {
        $this->assertConfigured();
        $this->beforeExecution();
    }

    /**
     * Ensure every required config key is present and non-empty, failing fast
     * with a clear message before any Python subprocess is spawned.
     *
     * Protected (rather than folded into beforeExecution()) so both the
     * synchronous preflight() and the asynchronous submitCircuit()/checkTask()
     * paths enforce it directly. A driver cannot skip config validation by
     * only overriding beforeExecution(), because that hook is never the one
     * responsible for running this check.
     */
    protected function assertConfigured(): void
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

    /**
     * Guard against a circuit that requests more qubits than the driver's
     * configured `max_qubits` ceiling allows.
     *
     * Statevector simulation memory doubles with every additional qubit, so
     * an unbounded circuit can exhaust host memory well before it would ever
     * reach a remote device's own limits. A null/absent `max_qubits` means
     * unlimited — the default for every driver, so existing configs keep
     * working unchanged.
     *
     * Called from executeCircuit() and executeBatch(), the two entry points
     * every synchronous and asynchronous execution path funnels through
     * (submitCircuit() on the local driver runs the circuit synchronously via
     * executeCircuit(), so ->dispatch() is covered too).
     *
     * @throws InvalidCircuitException
     */
    private function assertWithinQubitCeiling(CircuitBuilder $circuit): void
    {
        $ceiling = $this->config['max_qubits'] ?? null;

        if ($ceiling === null) {
            return;
        }

        $requested = $circuit->qubitCount();

        if ($requested > (int) $ceiling) {
            throw InvalidCircuitException::qubitCeilingExceeded($requested, (int) $ceiling, $this->driverName());
        }
    }

    /**
     * Execute the given circuits in batch on the device and return the measurement results.
     *
     * @param  CircuitBuilder[]  $circuits
     *
     * @throws InvalidCircuitException
     */
    public function executeBatch(array $circuits): BatchResult
    {
        $this->preflight();

        foreach ($circuits as $circuit) {
            $this->assertWithinQubitCeiling($circuit);
        }

        $payload = [
            'circuits' => array_map(static fn (CircuitBuilder $c): array => $c->toArray(), $circuits),
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('batch.py', $payload, $this->config);

        if (! array_key_exists('results', $response) || ! is_array($response['results'])) {
            throw QuantumExecutionException::malformedResponse(
                'batch.py',
                'expected the "results" key to be present and hold an array.'
            );
        }

        if (count($response['results']) !== count($circuits)) {
            throw QuantumExecutionException::malformedResponse(
                'batch.py',
                'expected exactly '.count($circuits).' results, got '.count($response['results']).'.'
            );
        }

        $circuitResults = [];
        foreach ($response['results'] as $result) {
            if (! is_array($result) || ! array_key_exists('counts', $result) || ! is_array($result['counts'])) {
                throw QuantumExecutionException::malformedResponse(
                    'batch.py',
                    'expected each result to have a "counts" array.'
                );
            }

            $counts = [];
            foreach ($result['counts'] as $bitstring => $count) {
                $counts[(string) $bitstring] = $count;
            }
            $circuitResults[] = new CircuitResult($counts);
        }

        return new BatchResult($circuitResults);
    }

    /**
     * @throws InvalidCircuitException
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->preflight();
        $this->assertWithinQubitCeiling($circuit);

        $payload = array_merge($circuit->toArray(), [
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ]);

        $response = $this->bridge->execute('circuit.py', $payload, $this->config);

        if (! array_key_exists('counts', $response) || ! is_array($response['counts'])) {
            throw QuantumExecutionException::malformedResponse(
                'circuit.py',
                'expected the "counts" key to be present and hold an array.'
            );
        }

        // json_decode() turns numeric-string keys (e.g. "10") into int keys,
        // which would silently break the array<string, int> contract of
        // CircuitResult::counts(). Normalize them back to strings here.
        $counts = [];

        foreach ($response['counts'] as $bitstring => $count) {
            $counts[(string) $bitstring] = $count;
        }

        return new CircuitResult($counts);
    }

    public function generateEntropy(int $bits): string
    {
        $this->preflight();

        $qubits = (int) ($this->config['entropy_qubits'] ?? 16);

        // A non-positive qubit count would otherwise cause a DivisionByZeroError
        // below. Config-level misconfiguration here is non-critical, so we fall
        // back to the safe default instead of failing the whole request.
        if ($qubits <= 0) {
            $qubits = 16;
        }

        $shots = (int) ceil($bits / $qubits);

        $payload = [
            'qubits' => $qubits,
            'shots' => $shots,
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ];

        $response = $this->bridge->execute('entropy.py', $payload, $this->config);

        if (! array_key_exists('bits', $response) || ! is_string($response['bits'])) {
            throw QuantumExecutionException::malformedResponse(
                'entropy.py',
                'expected the "bits" key to be present and hold a string.'
            );
        }

        if (strlen($response['bits']) < $bits) {
            throw QuantumExecutionException::malformedResponse(
                'entropy.py',
                "expected at least {$bits} bits in the response, got ".strlen($response['bits']).'.'
            );
        }

        $bitstring = substr($response['bits'], 0, $bits);

        return $this->bridge->bitstringToBytes($bitstring);
    }
}
