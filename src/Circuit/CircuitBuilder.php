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
            foreach ($gate->qubitIndices() as $target) {
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
        return $this->push(Gate::h($target));
    }

    /**
     * Add a Pauli-X (NOT) gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function x(int $target): static
    {
        return $this->push(Gate::x($target));
    }

    /**
     * Add a Pauli-Y gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function y(int $target): static
    {
        return $this->push(Gate::y($target));
    }

    /**
     * Add a Pauli-Z gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function z(int $target): static
    {
        return $this->push(Gate::z($target));
    }

    /**
     * Add an Identity gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function i(int $target): static
    {
        return $this->push(Gate::i($target));
    }

    /**
     * Add a Controlled-NOT gate.
     *
     * @throws InvalidCircuitException
     */
    public function cnot(int $control, int $target): static
    {
        return $this->push(Gate::cnot($control, $target));
    }

    /**
     * Add a Phase-S gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function s(int $target): static
    {
        return $this->push(Gate::s($target));
    }

    /**
     * Add a Phase-S† (adjoint S) gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function si(int $target): static
    {
        return $this->push(Gate::si($target));
    }

    /**
     * Add a Phase-T gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function t(int $target): static
    {
        return $this->push(Gate::t($target));
    }

    /**
     * Add a Phase-T† (adjoint T) gate on the given qubit.
     *
     * @throws InvalidCircuitException
     */
    public function ti(int $target): static
    {
        return $this->push(Gate::ti($target));
    }

    /**
     * Add a rotation around the X-axis.
     *
     * @throws InvalidCircuitException
     */
    public function rx(int $target, float|Angle $angle): static
    {
        return $this->push(Gate::rx($target, $angle));
    }

    /**
     * Add a rotation around the Y-axis.
     *
     * @throws InvalidCircuitException
     */
    public function ry(int $target, float|Angle $angle): static
    {
        return $this->push(Gate::ry($target, $angle));
    }

    /**
     * Add a rotation around the Z-axis.
     *
     * @throws InvalidCircuitException
     */
    public function rz(int $target, float|Angle $angle): static
    {
        return $this->push(Gate::rz($target, $angle));
    }

    /**
     * Add a SWAP gate.
     *
     * @throws InvalidCircuitException
     */
    public function swap(int $qubit0, int $qubit1): static
    {
        return $this->push(Gate::swap($qubit0, $qubit1));
    }

    /**
     * Add a Controlled-Z gate.
     *
     * @throws InvalidCircuitException
     */
    public function cz(int $control, int $target): static
    {
        return $this->push(Gate::cz($control, $target));
    }

    /**
     * Add a Controlled-Y gate.
     *
     * @throws InvalidCircuitException
     */
    public function cy(int $control, int $target): static
    {
        return $this->push(Gate::cy($control, $target));
    }

    /**
     * Add a Toffoli (CCNOT) gate.
     *
     * @throws InvalidCircuitException
     */
    public function ccnot(int $control0, int $control1, int $target): static
    {
        return $this->push(Gate::ccnot($control0, $control1, $target));
    }

    /**
     * Add a Controlled-RX gate.
     *
     * @throws InvalidCircuitException
     */
    public function crx(int $control, int $target, float|Angle $angle): static
    {
        return $this->push(Gate::crx($control, $target, $angle));
    }

    /**
     * Add a Controlled-RY gate.
     *
     * @throws InvalidCircuitException
     */
    public function cry(int $control, int $target, float|Angle $angle): static
    {
        return $this->push(Gate::cry($control, $target, $angle));
    }

    /**
     * Add a Controlled-RZ gate.
     *
     * @throws InvalidCircuitException
     */
    public function crz(int $control, int $target, float|Angle $angle): static
    {
        return $this->push(Gate::crz($control, $target, $angle));
    }

    /**
     * Add a Controlled-PhaseShift gate.
     *
     * @throws InvalidCircuitException
     */
    public function cphaseshift(int $control, int $target, float|Angle $angle): static
    {
        return $this->push(Gate::cphaseshift($control, $target, $angle));
    }

    /**
     * Add a PhaseShift gate.
     *
     * @throws InvalidCircuitException
     */
    public function phaseshift(int $target, float|Angle $angle): static
    {
        return $this->push(Gate::phaseshift($target, $angle));
    }

    /**
     * Add a U gate.
     *
     * @throws InvalidCircuitException
     */
    public function u(int $target, float|Angle $theta, float|Angle $phi, float|Angle $lambda): static
    {
        return $this->push(Gate::u($target, $theta, $phi, $lambda));
    }

    /**
     * Add a Controlled-SWAP (Fredkin) gate.
     *
     * @throws InvalidCircuitException
     */
    public function cswap(int $control, int $qubit0, int $qubit1): static
    {
        return $this->push(Gate::cswap($control, $qubit0, $qubit1));
    }

    /**
     * Add an iSWAP gate.
     *
     * @throws InvalidCircuitException
     */
    public function iswap(int $qubit0, int $qubit1): static
    {
        return $this->push(Gate::iswap($qubit0, $qubit1));
    }

    /**
     * Add an XX gate.
     *
     * @throws InvalidCircuitException
     */
    public function xx(int $qubit0, int $qubit1, float|Angle $angle): static
    {
        return $this->push(Gate::xx($qubit0, $qubit1, $angle));
    }

    /**
     * Add a YY gate.
     *
     * @throws InvalidCircuitException
     */
    public function yy(int $qubit0, int $qubit1, float|Angle $angle): static
    {
        return $this->push(Gate::yy($qubit0, $qubit1, $angle));
    }

    /**
     * Add a ZZ gate.
     *
     * @throws InvalidCircuitException
     */
    public function zz(int $qubit0, int $qubit1, float|Angle $angle): static
    {
        return $this->push(Gate::zz($qubit0, $qubit1, $angle));
    }

    /**
     * Append another circuit or a callable fragment to this circuit.
     *
     * If a CircuitBuilder is passed, its gates (except measurements) are copied over.
     * If a callable is passed, it receives a new, isolated CircuitBuilder (with the same qubit count).
     *
     * @throws InvalidCircuitException if this circuit has no qubits yet, or the
     *                                 appended circuit requires more qubits than are available.
     */
    public function append(self|callable $fragment): static
    {
        if ($this->qubitCount === 0) {
            throw InvalidCircuitException::noQubits();
        }

        if (is_callable($fragment)) {
            $builder = new static($this->device, $this->driverName);
            $builder->qubits($this->qubitCount);
            $fragment($builder);
            $fragment = $builder;
        }

        if ($fragment->qubitCount() > $this->qubitCount) {
            throw InvalidCircuitException::appendedCircuitTooLarge(
                $fragment->qubitCount(),
                $this->qubitCount
            );
        }

        // Gate is an immutable value object and the fragment's gates were
        // already validated against its own (not larger) qubit count, so they
        // can be shared directly without a toArray()/Gate::fromArray() round
        // trip that would re-serialize and re-validate every gate.
        foreach ($fragment->gates as $gate) {
            if (! $gate->isMeasurement()) {
                $this->gates[] = $gate;
            }
        }

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
        if (is_array($targets) && $targets === []) {
            throw InvalidCircuitException::emptyMeasurementTargets();
        }

        return $this->push(Gate::measure($targets));
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
            $builder->push(Gate::fromArray($gate));
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
    public function validate(): static
    {
        if ($this->qubitCount === 0) {
            throw InvalidCircuitException::noQubits();
        }

        if (! $this->hasMeasurement) {
            throw InvalidCircuitException::noMeasurement();
        }

        return $this;
    }

    /**
     * Validate a gate against the circuit's qubit range, append it, and
     * track measurement state. The single append primitive behind every
     * fluent gate method and fromArray().
     *
     * @throws InvalidCircuitException
     */
    private function push(Gate $gate): static
    {
        $this->validateTargets(strtoupper($gate->type), ...$gate->qubitIndices());

        $this->gates[] = $gate;

        if ($gate->isMeasurement()) {
            $this->hasMeasurement = true;
        }

        return $this;
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
}
