<?php

declare(strict_types=1);

namespace Aether\Circuit;

use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\Results\CircuitResult;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;

/**
 * Fluent builder for quantum circuits.
 *
 * Typical usage:
 *
 *   $result = (new CircuitBuilder($device))
 *       ->qubits(2)
 *       ->h(0)
 *       ->cnot(0, 1)
 *       ->measure()
 *       ->shots(1024)
 *       ->run();
 */
class CircuitBuilder
{
    use Conditionable;
    use Tappable;

    private int $qubitCount = 0;

    /** @var Gate[] */
    private array $gates = [];

    private bool $hasMeasurement = false;

    private int $shots = 1000;

    final public function __construct(
        private readonly QuantumDevice $device,
        private readonly ?string $driverName = null,
    ) {}

    /**
     * Set the number of qubits in the circuit.
     *
     * If gates have already been added, shrinking the count below any of
     * their target indices is rejected to prevent building an invalid
     * circuit that would otherwise only fail opaquely at the Python layer.
     *
     * @throws InvalidCircuitException
     */
    public function qubits(int $count): static
    {
        if ($count < 1) {
            throw InvalidCircuitException::invalidQubitCount($count);
        }

        foreach ($this->gates as $gate) {
            foreach ($this->gateTargets($gate) as $target) {
                if ($target >= $count) {
                    throw InvalidCircuitException::qubitCountBelowExistingGates(
                        strtoupper($gate->type),
                        $target,
                        $count
                    );
                }
            }
        }

        $this->qubitCount = $count;

        return $this;
    }

    /**
     * Set the number of measurement shots.
     */
    public function shots(int $shots): static
    {
        if ($shots < 1) {
            throw InvalidCircuitException::invalidShotCount($shots);
        }

        $this->shots = $shots;

        return $this;
    }

    /**
     * Add a Hadamard gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function h(int $target): static
    {
        $this->validateTargets('H', $target);
        $this->gates[] = Gate::h($target);

        return $this;
    }

    /**
     * Add a Pauli-X (NOT) gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function x(int $target): static
    {
        $this->validateTargets('X', $target);
        $this->gates[] = Gate::x($target);

        return $this;
    }

    /**
     * Add a Pauli-Y gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function y(int $target): static
    {
        $this->validateTargets('Y', $target);
        $this->gates[] = Gate::y($target);

        return $this;
    }

    /**
     * Add a Pauli-Z gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function z(int $target): static
    {
        $this->validateTargets('Z', $target);
        $this->gates[] = Gate::z($target);

        return $this;
    }

    /**
     * Add a Controlled-NOT gate.
     *
     * @throws InvalidCircuitException
     */
    public function cnot(int $control, int $target): static
    {
        $this->validateTargets('CNOT', $control, $target);
        $this->gates[] = Gate::cnot($control, $target);

        return $this;
    }

    /**
     * Add a Phase-S gate on the given qubit.
     */
    public function s(int $target): static
    {
        $this->validateTargets('S', $target);
        $this->gates[] = Gate::s($target);

        return $this;
    }

    /**
     * Add a Phase-T gate on the given qubit.
     */
    public function t(int $target): static
    {
        $this->validateTargets('T', $target);
        $this->gates[] = Gate::t($target);

        return $this;
    }

    /**
     * Add a rotation around the X-axis.
     */
    public function rx(int $target, float|Angle $angle): static
    {
        $this->validateTargets('RX', $target);
        $this->gates[] = Gate::rx($target, $angle);

        return $this;
    }

    /**
     * Add a rotation around the Y-axis.
     */
    public function ry(int $target, float|Angle $angle): static
    {
        $this->validateTargets('RY', $target);
        $this->gates[] = Gate::ry($target, $angle);

        return $this;
    }

    /**
     * Add a rotation around the Z-axis.
     */
    public function rz(int $target, float|Angle $angle): static
    {
        $this->validateTargets('RZ', $target);
        $this->gates[] = Gate::rz($target, $angle);

        return $this;
    }

    /**
     * Add a SWAP gate.
     */
    public function swap(int $qubit0, int $qubit1): static
    {
        $this->validateTargets('SWAP', $qubit0, $qubit1);
        $this->gates[] = Gate::swap($qubit0, $qubit1);

        return $this;
    }

    /**
     * Add a Controlled-Z gate.
     */
    public function cz(int $control, int $target): static
    {
        $this->validateTargets('CZ', $control, $target);
        $this->gates[] = Gate::cz($control, $target);

        return $this;
    }

    /**
     * Add a Toffoli (CCNOT) gate.
     */
    public function ccnot(int $control0, int $control1, int $target): static
    {
        $this->validateTargets('CCNOT', $control0, $control1, $target);
        $this->gates[] = Gate::ccnot($control0, $control1, $target);

        return $this;
    }

    /**
     * Add a measurement gate.
     *
     * - Pass null (default) to measure all qubits.
     * - Pass an int to measure a single qubit.
     * - Pass an array to measure the specified qubits.
     *
     * @param  int|int[]|null  $targets
     */
    public function measure(int|array|null $targets = null): static
    {
        if (is_int($targets)) {
            $this->validateTargets('Measure', $targets);
        } elseif (is_array($targets)) {
            if ($targets === []) {
                throw InvalidCircuitException::emptyMeasurementTargets();
            }

            $this->validateTargets('Measure', ...$targets);
        }

        $this->gates[] = Gate::measure($targets);
        $this->hasMeasurement = true;

        return $this;
    }

    /**
     * Return the configured qubit count.
     */
    public function qubitCount(): int
    {
        return $this->qubitCount;
    }

    /**
     * Return the configured shot count.
     */
    public function shotCount(): int
    {
        return $this->shots;
    }

    /**
     * Return the name of the driver this builder was created for, or null
     * when it was built without an explicit driver name (e.g. constructed
     * directly rather than via QuantumManager::circuit()).
     */
    public function driverName(): ?string
    {
        return $this->driverName;
    }

    /**
     * Validate and execute the circuit on the configured device.
     *
     * @throws InvalidCircuitException
     */
    public function run(): CircuitResult
    {
        $this->validate();

        return $this->device->executeCircuit($this);
    }

    /**
     * Validate the circuit and dispatch it to the queue for asynchronous
     * execution, instead of blocking on synchronous execution like run().
     *
     * The circuit is serialized via toArray() so it survives queue
     * serialization, and reconstructed with CircuitBuilder::fromArray() by
     * the job once it runs.
     *
     * @return PendingDispatch Laravel's pending dispatch, chainable with ->onQueue() / ->delay().
     *
     * @throws InvalidCircuitException
     */
    public function dispatch(): PendingDispatch
    {
        $this->validate();

        return SubmitQuantumCircuit::dispatch($this->toArray(), $this->driverName);
    }

    /**
     * Same as dispatch(), routing the job to the given queue when a name is provided.
     *
     * @return PendingDispatch Laravel's pending dispatch, chainable with ->delay() etc.
     *
     * @throws InvalidCircuitException
     */
    public function queue(?string $queue = null): PendingDispatch
    {
        $pending = $this->dispatch();

        if ($queue !== null) {
            $pending->onQueue($queue);
        }

        return $pending;
    }

    /**
     * Serialize the circuit to a plain array suitable for JSON encoding or
     * passing directly to a driver.
     *
     * @return array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}
     */
    public function toArray(): array
    {
        return [
            'qubits' => $this->qubitCount,
            'gates' => array_map(static fn (Gate $gate): array => $gate->toArray(), $this->gates),
            'shots' => $this->shots,
        ];
    }

    /**
     * Rebuild a CircuitBuilder from the array shape produced by toArray().
     *
     * Used by the queued job to reconstruct the circuit after it has been
     * serialized onto the queue and deserialized on the worker.
     *
     * @param  array{qubits?: int, gates?: array<int, array<string, mixed>>, shots?: int}  $definition
     *
     * @throws InvalidCircuitException
     */
    public static function fromArray(array $definition, QuantumDevice $device, ?string $driverName = null): static
    {
        $builder = new static($device, $driverName);
        $builder->qubits((int) ($definition['qubits'] ?? 0));

        /** @var array<string, mixed> $gate */
        foreach ($definition['gates'] ?? [] as $gate) {
            $builder->applyGateDefinition($gate);
        }

        $builder->shots((int) ($definition['shots'] ?? 1000));

        return $builder;
    }

    /**
     * Assert that the circuit is complete enough to be executed or dispatched:
     * at least one qubit, and at least one measurement operation.
     *
     * @throws InvalidCircuitException
     */
    private function validate(): void
    {
        if ($this->qubitCount === 0) {
            throw InvalidCircuitException::noQubits();
        }

        if (! $this->hasMeasurement) {
            throw InvalidCircuitException::noMeasurement();
        }
    }

    /**
     * Apply a single serialized gate definition (as produced by Gate::toArray())
     * to this builder, dispatching to the matching fluent method by gate type.
     *
     * @param  array<string, mixed>  $gate
     *
     * @throws InvalidCircuitException
     */
    private function applyGateDefinition(array $gate): void
    {
        $type = is_string($gate['type'] ?? null) ? $gate['type'] : '';

        match ($type) {
            'h' => $this->h((int) $gate['target']),
            'x' => $this->x((int) $gate['target']),
            'y' => $this->y((int) $gate['target']),
            'z' => $this->z((int) $gate['target']),
            's' => $this->s((int) $gate['target']),
            't' => $this->t((int) $gate['target']),
            'rx' => $this->rx((int) $gate['target'], (float) $gate['angle']),
            'ry' => $this->ry((int) $gate['target'], (float) $gate['angle']),
            'rz' => $this->rz((int) $gate['target'], (float) $gate['angle']),
            'cnot' => $this->cnot((int) $gate['control'], (int) $gate['target']),
            'cz' => $this->cz((int) $gate['control'], (int) $gate['target']),
            'swap' => $this->swap((int) $gate['target0'], (int) $gate['target1']),
            'ccnot' => $this->ccnot((int) $gate['control0'], (int) $gate['control1'], (int) $gate['target']),
            'measure' => $this->measure($this->decodeMeasureTargets($gate)),
            default => throw InvalidCircuitException::unknownGateType($type),
        };
    }

    /**
     * Decode the `targets` value of a serialized measure gate back into the
     * int[]|null shape expected by measure().
     *
     * @param  array<string, mixed>  $gate
     * @return int[]|null
     */
    private function decodeMeasureTargets(array $gate): ?array
    {
        $targets = $gate['targets'] ?? null;

        if (! is_array($targets)) {
            return null;
        }

        return array_map(static fn (mixed $target): int => (int) $target, $targets);
    }

    /**
     * Assert that all given qubit indices are within the valid range for this circuit.
     *
     * @throws InvalidCircuitException
     */
    private function validateTargets(string $gate, int ...$qubits): void
    {
        foreach ($qubits as $qubit) {
            if ($qubit < 0 || $qubit >= $this->qubitCount) {
                throw InvalidCircuitException::gateTargetOutOfRange($gate, $qubit, $this->qubitCount);
            }
        }
    }

    /**
     * Return the qubit indices referenced by a gate.
     *
     * Measure gates store their targets under the `targets` key, which may
     * be `null` (meaning "all qubits", and therefore always valid). Every
     * other gate stores qubit indices as plain int params, with the sole
     * exception of the `angle` param used by rotation gates.
     *
     * @return int[]
     */
    private function gateTargets(Gate $gate): array
    {
        if ($gate->type === 'measure') {
            return $gate->params['targets'] ?? [];
        }

        return array_values(array_filter(
            $gate->params,
            static fn (mixed $value, string $key): bool => $key !== 'angle' && is_int($value),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
