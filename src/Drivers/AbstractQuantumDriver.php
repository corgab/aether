<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Concerns\DispatchesLifecycleEvents;
use Aether\Config\DriverConfig;
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
 *
 * @template TConfig of DriverConfig
 */
abstract class AbstractQuantumDriver implements BatchableDevice, QuantumDevice
{
    private const BITS_PER_BYTE = 8;

    use DispatchesLifecycleEvents;

    /**
     * Typed driver options, built once from the raw array by makeConfig().
     *
     * @var TConfig
     */
    protected readonly DriverConfig $config;

    /**
     * @param  array<string, mixed>  $config  The raw `aether.drivers.<name>` array.
     *
     * @throws InvalidDriverConfigException When an option has a value of the wrong shape.
     */
    public function __construct(
        protected readonly PythonExecutor $bridge,
        array $config,
    ) {
        $this->config = $this->makeConfig($config);
    }

    /**
     * Return the driver identifier passed to Python scripts.
     *
     * Called from the base constructor (through makeConfig()) before the
     * subclass constructor body runs, so it must not depend on state a
     * subclass sets after parent::__construct(): return a literal or a
     * promoted constructor parameter.
     */
    abstract protected function driverName(): string;

    /**
     * Build the typed config object for this driver.
     *
     * Override in drivers with options of their own (see AwsBraketDriver) to
     * return a DriverConfig subclass; the base class types the shared options
     * (`max_qubits`, `entropy_qubits`, `synchronous_safe`) and keeps every
     * other key reachable through DriverConfig::get() and the JSON payload.
     *
     * Runs inside the base constructor, before the subclass constructor body,
     * so it (and the driverName() it calls) can only rely on promoted
     * constructor parameters, not on properties assigned afterwards.
     *
     * @param  array<string, mixed>  $values
     * @return TConfig
     *
     * @throws InvalidDriverConfigException
     */
    protected function makeConfig(array $values): DriverConfig
    {
        /** @var TConfig */
        return new DriverConfig($this->driverName(), $values);
    }

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
        $synchronousSafe = $this->config->synchronousSafe;

        if ($synchronousSafe === true) {
            return;
        }

        if ($synchronousSafe === false) {
            throw QuantumExecutionException::synchronousUnsafe($this->driverName());
        }

        if (! $this->isSynchronousSafeByDefault()) {
            $deviceArn = (string) ($this->config->get('device_arn') ?? 'unknown');
            throw QuantumExecutionException::synchronousUnsafeForQpu($this->driverName(), $deviceArn);
        }
    }

    /**
     * Determine whether a Braket device ARN identifies real QPU hardware
     * rather than a managed simulator. QPU ARNs have the shape
     * `arn:aws:braket:<region>::device/qpu/<provider>/<name>` — note the
     * empty account-id field, so the resource segment "device/qpu/..." is
     * preceded by a colon, not a slash.
     */
    protected function isSynchronousSafeByDefault(): bool
    {
        return true;
    }

    /**
     * Admission checks every circuit must pass before it reaches Python.
     *
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException
     */
    protected function validateCircuits(array $circuits): void
    {
        $ceiling = $this->config->maxQubits;

        if ($ceiling === null) {
            return;
        }

        foreach ($circuits as $circuit) {
            $this->assertWithinQubitCeiling($circuit, $ceiling);
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
    private function preflightSynchronous(): void
    {
        $this->assertConfigured();
        $this->assertSynchronousSafe();
        $this->beforeExecution();
    }

    /**
     * Ensure every required config key is present and non-empty, failing fast
     * with a clear message before any Python subprocess is spawned.
     */
    protected function assertConfigured(): void
    {
        $missing = $this->config->blankKeys($this->requiredConfig());

        if ($missing !== []) {
            throw InvalidDriverConfigException::missingKeys($this->driverName(), $missing);
        }
    }

    /**
     * Read a string option from the driver config, trimmed, treating a
     * missing, non-string or blank value as unset.
     */
    protected function configString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Guard against a circuit that requests more qubits than the driver's
     * configured `max_qubits` ceiling allows.
     * Statevector simulation memory doubles with every additional qubit, so
     * an unbounded circuit can exhaust host memory well before it would ever
     * reach a remote device's own limits. A blank `max_qubits` means
     * unlimited (DriverConfig::$maxQubits is null and validateCircuits()
     * never gets here), the default for every driver.
     *
     * @throws InvalidCircuitException
     */
    private function assertWithinQubitCeiling(CircuitBuilder $circuit, int $ceiling): void
    {
        $requested = $circuit->qubitCount();

        if ($requested > $ceiling) {
            throw InvalidCircuitException::qubitCeilingExceeded($requested, $ceiling, $this->driverName());
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
            'driver_config' => $this->config->toArray(),
        ]);
    }

    /**
     * Execute the given circuits in batch on the device and return the measurement results.
     *
     * @param  CircuitBuilder[]  $circuits
     *
     * @throws InvalidCircuitException When the batch is empty or a circuit fails validation.
     */
    public function executeBatch(array $circuits): BatchResult
    {
        if ($circuits === []) {
            throw InvalidCircuitException::emptyBatch();
        }

        $this->preflightSynchronous();
        $this->validateCircuits(array_values($circuits));

        $definitions = array_map(static fn (CircuitBuilder $c): array => $c->toArray(), array_values($circuits));
        $circuitResults = $this->runBatchDefinitions($definitions);

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
        $this->preflightSynchronous();
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
     * Unlike preflightSynchronous(), this skips assertSynchronousSafe(): the inline run
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
        $response = $this->bridge->execute('circuit.py', $this->payload($definition), $this->config->toArray());

        if (! array_key_exists('counts', $response) || ! is_array($response['counts'])) {
            throw QuantumExecutionException::malformedResponse(
                'circuit.py',
                'expected the "counts" key to be present and hold an array.'
            );
        }

        return new CircuitResult($response['counts']);
    }

    /**
     * Send an already-validated array of circuit definitions to batch.py and
     * parse the measurement counts it returns.
     *
     * @param  list<array<string, mixed>>  $definitions  The CircuitBuilder::toArray() shapes.
     * @return list<CircuitResult>
     *
     * @throws QuantumExecutionException When the response is malformed.
     */
    private function runBatchDefinitions(array $definitions): array
    {
        $payload = $this->payload([
            'circuits' => $definitions,
        ]);

        $response = $this->bridge->execute('batch.py', $payload, $this->config->toArray());

        if (! array_key_exists('results', $response) || ! is_array($response['results'])) {
            throw QuantumExecutionException::malformedResponse(
                'batch.py',
                'expected the "results" key to be present and hold an array.'
            );
        }

        if (count($response['results']) !== count($definitions)) {
            throw QuantumExecutionException::malformedResponse(
                'batch.py',
                'expected exactly '.count($definitions).' results, got '.count($response['results']).'.'
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

        return $circuitResults;
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

        $response = $this->bridge->execute('submit.py', $this->payload($circuit->toArray()), $this->config->toArray());

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

        $response = $this->bridge->execute('check.py', $this->payload(['task_arn' => $taskArn]), $this->config->toArray());

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

    /**
     * Returns ceil($bits / 8) bytes, every bit of which was measured: the
     * request is rounded up to whole bytes before it reaches the device.
     */
    public function generateEntropy(int $bits): string
    {
        if ($bits < 1) {
            throw QuantumExecutionException::invalidEntropyBitCount($bits);
        }

        $this->preflightSynchronous();

        $qubits = $this->config->entropyQubits;

        // Fetch whole bytes: a final chunk shorter than 8 bits would be
        // zero-padded into a byte whose high bits are never random.
        $bitsToFetch = (int) ceil($bits / self::BITS_PER_BYTE) * self::BITS_PER_BYTE;
        $shots = (int) ceil($bitsToFetch / $qubits);

        try {
            $this->validateCircuits([$this->entropyCircuit($qubits, $shots)]);
        } catch (InvalidCircuitException $e) {
            throw InvalidCircuitException::entropyRejected($bits, $qubits, $shots, $e);
        }

        $payload = $this->payload([
            'qubits' => $qubits,
            'shots' => $shots,
        ]);

        $response = $this->bridge->execute('entropy.py', $payload, $this->config->toArray());

        if (! array_key_exists('bits', $response) || ! is_string($response['bits'])) {
            throw QuantumExecutionException::malformedResponse(
                'entropy.py',
                'expected the "bits" key to be present and hold a string.'
            );
        }

        if (preg_match('/^[01]*$/D', $response['bits']) !== 1) {
            throw QuantumExecutionException::malformedResponse(
                'entropy.py',
                'expected the "bits" value to contain only 0 and 1 digits.'
            );
        }

        if (strlen($response['bits']) < $bitsToFetch) {
            throw QuantumExecutionException::malformedResponse(
                'entropy.py',
                "expected at least {$bitsToFetch} bits in the response, got ".strlen($response['bits']).'.'
            );
        }

        $bitstring = substr($response['bits'], 0, $bitsToFetch);

        $this->dispatchEvent(new EntropyGenerated($this->driverName(), $bits));

        return $this->bridge->bitstringToBytes($bitstring);
    }
}
