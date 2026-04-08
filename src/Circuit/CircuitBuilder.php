<?php

declare(strict_types=1);

namespace Aether\Circuit;

use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Results\CircuitResult;

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
    private int $qubitCount = 0;

    /** @var Gate[] */
    private array $gates = [];

    private bool $hasMeasurement = false;

    private int $shots = 1000;

    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Set the number of qubits in the circuit.
     */
    public function qubits(int $count): static
    {
        if ($count < 1) {
            throw InvalidCircuitException::invalidQubitCount($count);
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
     * Add a barrier (logical separator, no hardware effect).
     */
    public function barrier(): static
    {
        $this->gates[] = Gate::barrier();

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
     * Validate and execute the circuit on the configured device.
     *
     * @throws InvalidCircuitException
     */
    public function run(): CircuitResult
    {
        if ($this->qubitCount === 0) {
            throw InvalidCircuitException::noQubits();
        }

        if (! $this->hasMeasurement) {
            throw InvalidCircuitException::noMeasurement();
        }

        return $this->device->executeCircuit($this);
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
