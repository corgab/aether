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

    /**
     * @param  QuantumDevice  $device
     */
    public function __construct(private readonly QuantumDevice $device) {}

    /**
     * Set the number of qubits in the circuit.
     *
     * @param  int  $count
     */
    public function qubits(int $count): static
    {
        $this->qubitCount = $count;

        return $this;
    }

    /**
     * Set the number of measurement shots.
     *
     * @param  int  $shots
     */
    public function shots(int $shots): static
    {
        $this->shots = $shots;

        return $this;
    }

    /**
     * Add a Hadamard gate on the given qubit.
     *
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    public function h(int $target): static
    {
        $this->assertTargetInRange('H', $target);
        $this->gates[] = Gate::h($target);

        return $this;
    }

    /**
     * Add a Pauli-X (NOT) gate on the given qubit.
     *
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    public function x(int $target): static
    {
        $this->assertTargetInRange('X', $target);
        $this->gates[] = Gate::x($target);

        return $this;
    }

    /**
     * Add a Pauli-Y gate on the given qubit.
     *
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    public function y(int $target): static
    {
        $this->assertTargetInRange('Y', $target);
        $this->gates[] = Gate::y($target);

        return $this;
    }

    /**
     * Add a Pauli-Z gate on the given qubit.
     *
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    public function z(int $target): static
    {
        $this->assertTargetInRange('Z', $target);
        $this->gates[] = Gate::z($target);

        return $this;
    }

    /**
     * Add a Controlled-NOT gate.
     *
     * @param  int  $control
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    public function cnot(int $control, int $target): static
    {
        $this->assertTargetInRange('CNOT', $control);
        $this->assertTargetInRange('CNOT', $target);
        $this->gates[] = Gate::cnot($control, $target);

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
            'gates'  => array_map(static fn (Gate $gate): array => $gate->toArray(), $this->gates),
            'shots'  => $this->shots,
        ];
    }

    /**
     * Assert that the given qubit index is within the valid range for this circuit.
     *
     * @param  string  $gate
     * @param  int  $target
     *
     * @throws InvalidCircuitException
     */
    private function assertTargetInRange(string $gate, int $target): void
    {
        if ($target >= $this->qubitCount) {
            throw InvalidCircuitException::gateTargetOutOfRange($gate, $target, $this->qubitCount);
        }
    }
}
