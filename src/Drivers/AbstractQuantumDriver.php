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
     * Hook for other driver-specific pre-flight logic.
     *
     * Runs before every circuit execution and entropy generation, after the
     * required-config and synchronous-safety checks. Default is a no-op;
     * overrides need no parent call.
     */
    protected function beforeExecution(): void {}

    /**
     * Refuse synchronous execution per the tri-state `synchronous_safe`
     * config: `true` always allows it, `false` always refuses it, and the
     * default `null` (or any non-bool value) derives the answer from
     * `device_arn` — a Braket QPU ARN refuses, anything else (a simulator,
     * or no ARN at all) is allowed.
     *
     * @throws QuantumExecutionException
     */
    protected function assertSynchronousSafe(): void
    {
        $synchronousSafe = $this->synchronousSafeFlag();

        if ($synchronousSafe === true) {
            return;
        }

        if ($synchronousSafe === false) {
            throw QuantumExecutionException::synchronousUnsafe($this->driverName());
        }

        $deviceArn = $this->config['device_arn'] ?? null;

        if (is_string($deviceArn) && static::isQpuArn($deviceArn)) {
            throw QuantumExecutionException::synchronousUnsafeForQpu($this->driverName(), $deviceArn);
        }
    }

    /**
     * Read `synchronous_safe` as a real tri-state value: a blank entry (absent,
     * null, or the empty string an unset env var yields) is null, booleans pass
     * through, and boolean-like strings such as "false" or "0" are accepted so
     * a value that clearly means "refuse" is never mistaken for "unset".
     *
     * @throws InvalidDriverConfigException When the value is none of those.
     */
    private function synchronousSafeFlag(): ?bool
    {
        $value = $this->config['synchronous_safe'] ?? null;

        if (blank($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $flag = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        if ($flag === null) {
            throw InvalidDriverConfigException::invalidValue(
                $this->driverName(),
                'synchronous_safe',
                $value,
                'true, false or null'
            );
        }

        return $flag;
    }

    /**
     * Determine whether a Braket device ARN identifies real QPU hardware
     * rather than a managed simulator. QPU ARNs have the shape
     * `arn:aws:braket:<region>::device/qpu/<provider>/<name>` — note the
     * empty account-id field, so the resource segment "device/qpu/..." is
     * preceded by a colon, not a slash.
     */
    protected static function isQpuArn(string $arn): bool
    {
        return str_contains($arn, 'device/qpu/');
    }

    /**
     * Admission checks every circuit must pass before it reaches Python.
     *
     * This is the single funnel for per-circuit guards: executeCircuit(),
     * executeBatch() and submitTask() all call it, so a guard added here (or
     * in an override) holds on ->run(), Quantum::batch() and ->dispatch()
     * alike. Concrete drivers extend it by overriding and calling the parent
     * first — see AwsBraketDriver::validateCircuits() for the cost ceiling.
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
     * The config and synchronous-safety checks live in dedicated methods
     * rather than in beforeExecution() so a driver overriding the hook
     * cannot silently skip either by forgetting to call the parent
     * implementation. assertSynchronousSafe() applies to every subclass,
     * built-in or custom, so QPU protection is never opt-in.
     *
     * Asynchronous paths (submitTask()/pollTask()) must NOT go through this
     * method: submitting a task or polling it never blocks on the QPU, so
     * assertSynchronousSafe() must not fire for them. They call
     * assertConfigured() directly instead — see its docblock for why that
     * still enforces validation.
     */
    private function preflight(): void
    {
        $this->assertConfigured();
        $this->assertSynchronousSafe();
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
     * Unlike preflight(), this skips assertSynchronousSafe(): the inline run
     * is the implementation of an asynchronous dispatch, which must never be
     * refused, and it only ever blocks the local machine, never a QPU queue.
     *
     * @throws InvalidCircuitException
     */
    protected function runCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->assertConfigured();
        $this->beforeExecution();
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
     * assertSynchronousSafe() deliberately does not run here.
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
     * blocks, so only config validation runs — neither assertSynchronousSafe()
     * nor beforeExecution().
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
