<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Concerns\DispatchesLifecycleEvents;
use Aether\Contracts\BatchableDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitExecuted;
use Aether\Events\EntropyGenerated;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

/**
 * Base driver with shared circuit execution and entropy generation logic.
 */
abstract class AbstractQuantumDriver implements BatchableDevice, QuantumDevice
{
    use DispatchesLifecycleEvents;

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
     * Admission checks every circuit must pass before it reaches Python.
     *
     * This is the single funnel for per-circuit guards: executeCircuit(),
     * executeBatch(), submitTask() and generateEntropy() all call it, so a
     * guard added here (or in an override) holds on ->run(), Quantum::batch(),
     * ->dispatch() and Quantum::entropy() alike. Concrete drivers extend it
     * by overriding and calling the parent first — see
     * AwsBraketDriver::validateCircuits() for the cost ceiling.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     */
    protected function validateCircuits(array $circuits): void
    {
        foreach ($circuits as $circuit) {
            $this->assertWithinQubitCeiling($circuit);
        }
    }

    /**
     * Run the mandatory pre-flight steps before spawning a *synchronous*
     * Python subprocess (executeCircuit()/generateEntropy()).
     *
     * The config check lives in assertConfigured() rather than in
     * beforeExecution() so a driver overriding the hook cannot silently skip
     * validation by forgetting to call the parent implementation.
     *
     * Asynchronous paths (submitTask()/pollTask()) must NOT go through this
     * method: submitting a task or polling it never blocks on the QPU, so the
     * synchronous-safety hook (e.g. AwsBraketDriver::beforeExecution()) must
     * not fire for them. They call assertConfigured() directly instead — see
     * its docblock for why that still enforces validation.
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
     * synchronous preflight() and the asynchronous submitTask()/pollTask()
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
     * reach a remote device's own limits. A blank `max_qubits` (absent, null,
     * or an empty string — what env() yields for `AETHER_MAX_QUBITS=`) means
     * unlimited, the default for every driver, so existing configs keep
     * working unchanged.
     *
     * @throws InvalidCircuitException
     */
    private function assertWithinQubitCeiling(CircuitBuilder $circuit): void
    {
        $ceiling = $this->config['max_qubits'] ?? null;

        if (blank($ceiling)) {
            return;
        }

        $requested = $circuit->qubitCount();

        if ($requested > (int) $ceiling) {
            throw InvalidCircuitException::qubitCeilingExceeded($requested, (int) $ceiling, $this->driverName());
        }
    }

    /**
     * Wrap script input in the envelope every bin/python script expects: the
     * data itself plus the driver name and config the provider layer reads.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function payload(array $data): array
    {
        return array_merge($data, [
            'driver' => $this->driverName(),
            'driver_config' => $this->config,
        ]);
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
        $this->validateCircuits(array_values($circuits));

        $payload = $this->payload([
            'circuits' => array_map(static fn (CircuitBuilder $c): array => $c->toArray(), $circuits),
        ]);

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

            $circuitResults[] = new CircuitResult($result['counts']);
        }

        // Announced only once the whole response has been validated, so a
        // malformed batch dispatches nothing — the same all-or-nothing
        // contract executeCircuit() gives listeners for a single run.
        foreach (array_values($circuits) as $index => $circuit) {
            $this->dispatchEvent(new CircuitExecuted($this->driverName(), $circuit->toArray(), $circuitResults[$index]));
        }

        return new BatchResult($circuitResults);
    }

    /**
     * Execute the circuit synchronously and announce it via CircuitExecuted.
     *
     * @throws InvalidCircuitException
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->preflight();
        $this->validateCircuits([$circuit]);

        $definition = $circuit->toArray();
        $result = $this->runDefinition($definition);

        $this->dispatchEvent(new CircuitExecuted($this->driverName(), $definition, $result));

        return $result;
    }

    /**
     * Run the circuit synchronously through circuit.py and return its result,
     * without dispatching CircuitExecuted.
     *
     * Drivers that only *simulate* asynchronous submission by running the
     * circuit inline (see LocalSimulatorDriver::submitCircuit()) use this so a
     * ->dispatch() does not also fire the synchronous ->run() event: the
     * asynchronous path already announces completion via CircuitCompleted
     * from the polling job.
     *
     * @throws InvalidCircuitException
     */
    protected function runCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->preflight();
        $this->validateCircuits([$circuit]);

        return $this->runDefinition($circuit->toArray());
    }

    /**
     * Send an already-validated circuit definition to circuit.py and parse
     * the measurement counts it returns.
     *
     * @param  array<string, mixed>  $definition  The CircuitBuilder::toArray() shape.
     *
     * @throws QuantumExecutionException When the response carries no usable counts.
     */
    private function runDefinition(array $definition): CircuitResult
    {
        $response = $this->bridge->execute('circuit.py', $this->payload($definition), $this->config);

        if (! array_key_exists('counts', $response) || ! is_array($response['counts'])) {
            throw QuantumExecutionException::malformedResponse(
                'circuit.py',
                'expected the "counts" key to be present and hold an array.'
            );
        }

        return new CircuitResult($response['counts']);
    }

    /**
     * Submit the circuit through submit.py and return the backend's task
     * identifier, without waiting for the result.
     *
     * Shared implementation for drivers exposing it via
     * AsynchronousDevice::submitCircuit(). Runs config validation and the
     * circuit admission checks only — submitting never blocks on the QPU, so
     * the synchronous-safety hook in beforeExecution() deliberately does not
     * fire here.
     *
     * @throws InvalidCircuitException
     * @throws QuantumExecutionException When submit.py returns no usable task identifier.
     */
    protected function submitTask(CircuitBuilder $circuit): string
    {
        $this->assertConfigured();
        $this->validateCircuits([$circuit]);

        $response = $this->bridge->execute('submit.py', $this->payload($circuit->toArray()), $this->config);

        $taskArn = $response['task_arn'] ?? null;

        if (! is_string($taskArn) || trim($taskArn) === '') {
            throw QuantumExecutionException::malformedResponse(
                'submit.py',
                'expected the "task_arn" key to be present and hold a non-empty string.'
            );
        }

        return $taskArn;
    }

    /**
     * Poll a previously submitted task through check.py.
     *
     * Shared implementation for drivers exposing it via
     * AsynchronousDevice::checkTask(). Like submitTask(), polling never
     * blocks, so only config validation runs — not beforeExecution().
     *
     * @throws QuantumExecutionException When check.py returns no valid status.
     */
    protected function pollTask(string $taskArn): TaskSnapshot
    {
        $this->assertConfigured();

        $response = $this->bridge->execute('check.py', $this->payload(['task_arn' => $taskArn]), $this->config);

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
     * Describe the entropy circuit as a CircuitBuilder so it can be run
     * through the same admission funnel as any other circuit.
     *
     * Mirrors the circuit bin/python/entropy.py builds — a Hadamard on every
     * qubit, then a measurement of them all — so any guard added to the
     * funnel sees the same shape it would see for a user circuit. It is
     * never executed from PHP: it exists only so the `max_qubits` and (on
     * aws) `max_cost_per_run` ceilings apply to entropy generation exactly
     * as they do to ->run(), ->dispatch() and Quantum::batch().
     */
    private function entropyCircuit(int $qubits, int $shots): CircuitBuilder
    {
        $circuit = (new CircuitBuilder($this, $this->driverName()))->qubits($qubits);

        for ($qubit = 0; $qubit < $qubits; $qubit++) {
            $circuit->h($qubit);
        }

        return $circuit->measure()->shots($shots);
    }

    public function generateEntropy(int $bits): string
    {
        if ($bits < 1) {
            throw new \InvalidArgumentException("Requested bit count ({$bits}) must be a positive integer.");
        }

        $this->preflight();

        $qubits = (int) ($this->config['entropy_qubits'] ?? 16);

        // A non-positive qubit count would otherwise cause a DivisionByZeroError
        // below. Config-level misconfiguration here is non-critical, so we fall
        // back to the safe default instead of failing the whole request.
        if ($qubits <= 0) {
            $qubits = 16;
        }

        $shots = (int) ceil($bits / $qubits);

        try {
            $this->validateCircuits([$this->entropyCircuit($qubits, $shots)]);
        } catch (InvalidCircuitException $e) {
            throw InvalidCircuitException::entropyRejected($bits, $qubits, $shots, $e);
        }

        $payload = $this->payload([
            'qubits' => $qubits,
            'shots' => $shots,
        ]);

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

        $this->dispatchEvent(new EntropyGenerated($this->driverName(), $bits));

        return $this->bridge->bitstringToBytes($bitstring);
    }
}
