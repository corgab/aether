<?php

declare(strict_types=1);

namespace Aether\Circuit;

use Aether\Exceptions\InvalidCircuitException;

/**
 * Immutable value object representing a single quantum gate operation.
 */
final readonly class Gate
{
    /**
     * @param  array<string, mixed>  $params
     */
    private function __construct(
        public string $type,
        public array $params = [],
    ) {}

    /**
     * Build a gate of any type from positional qubit indices and angles.
     *
     * @param  int[]  $qubits  Qubit indices in the shape's wire order.
     * @param  array<float|Angle>  $angles  Angles in the shape's wire order.
     *
     * @throws InvalidCircuitException When the argument counts do not match the shape.
     */
    public static function make(GateType $type, array $qubits, array $angles = []): self
    {
        if ($type === GateType::Measure) {
            if ($angles !== []) {
                throw InvalidCircuitException::gateArity($type->value, 'angle', 0, count($angles));
            }

            return self::measure($qubits === [] ? null : array_values($qubits));
        }

        $shape = $type->shape();
        $qubitKeys = $shape->qubitKeys();
        $angleKeys = $shape->angleKeys();

        if (count($qubits) !== count($qubitKeys)) {
            throw InvalidCircuitException::gateArity($type->value, 'qubit', count($qubitKeys), count($qubits));
        }

        if (count($angles) !== count($angleKeys)) {
            throw InvalidCircuitException::gateArity($type->value, 'angle', count($angleKeys), count($angles));
        }

        $params = array_combine($qubitKeys, array_values($qubits))
            + array_combine($angleKeys, array_map(self::radians(...), array_values($angles)));

        return new self($type->value, $params);
    }

    /**
     * Create a Hadamard gate on the given qubit.
     */
    public static function h(int $target): self
    {
        return self::make(GateType::H, [$target]);
    }

    /**
     * Create a Pauli-X (NOT) gate on the given qubit.
     */
    public static function x(int $target): self
    {
        return self::make(GateType::X, [$target]);
    }

    /**
     * Create a Pauli-Y gate on the given qubit.
     */
    public static function y(int $target): self
    {
        return self::make(GateType::Y, [$target]);
    }

    /**
     * Create a Pauli-Z gate on the given qubit.
     */
    public static function z(int $target): self
    {
        return self::make(GateType::Z, [$target]);
    }

    /**
     * Create an Identity gate on the given qubit.
     */
    public static function i(int $target): self
    {
        return self::make(GateType::I, [$target]);
    }

    /**
     * Create a Phase-S gate on the given qubit.
     */
    public static function s(int $target): self
    {
        return self::make(GateType::S, [$target]);
    }

    /**
     * Create a Phase-S† (adjoint S) gate on the given qubit.
     */
    public static function si(int $target): self
    {
        return self::make(GateType::SI, [$target]);
    }

    /**
     * Create a Phase-T gate on the given qubit.
     */
    public static function t(int $target): self
    {
        return self::make(GateType::T, [$target]);
    }

    /**
     * Create a Phase-T† (adjoint T) gate on the given qubit.
     */
    public static function ti(int $target): self
    {
        return self::make(GateType::TI, [$target]);
    }

    /**
     * Create a rotation around the X-axis.
     */
    public static function rx(int $target, float|Angle $angle): self
    {
        return self::make(GateType::RX, [$target], [$angle]);
    }

    /**
     * Create a rotation around the Y-axis.
     */
    public static function ry(int $target, float|Angle $angle): self
    {
        return self::make(GateType::RY, [$target], [$angle]);
    }

    /**
     * Create a rotation around the Z-axis.
     */
    public static function rz(int $target, float|Angle $angle): self
    {
        return self::make(GateType::RZ, [$target], [$angle]);
    }

    /**
     * Create a Controlled-NOT gate.
     */
    public static function cnot(int $control, int $target): self
    {
        return self::make(GateType::CNOT, [$control, $target]);
    }

    /**
     * Create a Controlled-Z gate.
     */
    public static function cz(int $control, int $target): self
    {
        return self::make(GateType::CZ, [$control, $target]);
    }

    /**
     * Create a Controlled-Y gate.
     */
    public static function cy(int $control, int $target): self
    {
        return self::make(GateType::CY, [$control, $target]);
    }

    /**
     * Create a SWAP gate.
     */
    public static function swap(int $qubit0, int $qubit1): self
    {
        return self::make(GateType::Swap, [$qubit0, $qubit1]);
    }

    /**
     * Create a Toffoli (CCNOT) gate.
     */
    public static function ccnot(int $control0, int $control1, int $target): self
    {
        return self::make(GateType::CCNOT, [$control0, $control1, $target]);
    }

    /**
     * Create a Controlled-RX gate.
     */
    public static function crx(int $control, int $target, float|Angle $angle): self
    {
        return self::make(GateType::CRX, [$control, $target], [$angle]);
    }

    /**
     * Create a Controlled-RY gate.
     */
    public static function cry(int $control, int $target, float|Angle $angle): self
    {
        return self::make(GateType::CRY, [$control, $target], [$angle]);
    }

    /**
     * Create a Controlled-RZ gate.
     */
    public static function crz(int $control, int $target, float|Angle $angle): self
    {
        return self::make(GateType::CRZ, [$control, $target], [$angle]);
    }

    /**
     * Create a Controlled-PhaseShift gate.
     */
    public static function cphaseshift(int $control, int $target, float|Angle $angle): self
    {
        return self::make(GateType::CPhaseShift, [$control, $target], [$angle]);
    }

    /**
     * Create a PhaseShift gate.
     */
    public static function phaseshift(int $target, float|Angle $angle): self
    {
        return self::make(GateType::PhaseShift, [$target], [$angle]);
    }

    /**
     * Create a U gate.
     */
    public static function u(int $target, float|Angle $theta, float|Angle $phi, float|Angle $lambda): self
    {
        return self::make(GateType::U, [$target], [$theta, $phi, $lambda]);
    }

    /**
     * Create a Controlled-SWAP (Fredkin) gate.
     */
    public static function cswap(int $control, int $qubit0, int $qubit1): self
    {
        return self::make(GateType::CSwap, [$control, $qubit0, $qubit1]);
    }

    /**
     * Create an iSWAP gate.
     */
    public static function iswap(int $qubit0, int $qubit1): self
    {
        return self::make(GateType::ISwap, [$qubit0, $qubit1]);
    }

    /**
     * Create an XX gate.
     */
    public static function xx(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return self::make(GateType::XX, [$qubit0, $qubit1], [$angle]);
    }

    /**
     * Create a YY gate.
     */
    public static function yy(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return self::make(GateType::YY, [$qubit0, $qubit1], [$angle]);
    }

    /**
     * Create a ZZ gate.
     */
    public static function zz(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return self::make(GateType::ZZ, [$qubit0, $qubit1], [$angle]);
    }

    /**
     * Create a measurement gate.
     *
     * - Pass null (default) to measure all qubits.
     * - Pass an int to measure a single qubit.
     * - Pass a non-empty array to measure the specified qubits.
     *
     * @param  int|int[]|null  $targets
     *
     * @throws InvalidCircuitException
     */
    public static function measure(int|array|null $targets = null): self
    {
        if ($targets === []) {
            throw InvalidCircuitException::emptyMeasurementTargets();
        }

        $resolved = match (true) {
            $targets === null => null,
            is_int($targets) => [$targets],
            default => self::integerIndices('measure', $targets),
        };

        return new self('measure', ['targets' => $resolved]);
    }

    /**
     * Require an angle to be numeric.
     *
     * @throws InvalidCircuitException
     */
    private static function numericAngle(string $gate, mixed $value): int|float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw InvalidCircuitException::invalidAngle(strtoupper($gate), $value);
        }

        return $value;
    }

    /**
     * Require a qubit index to be an integer.
     *
     * PHP cannot type array elements or a serialized definition's values, so
     * a stray string or float would otherwise reach the int-typed range check
     * as a TypeError, or be cast to qubit 0 when a queued definition is rebuilt.
     *
     * @throws InvalidCircuitException
     */
    private static function integerIndex(string $gate, mixed $value): int
    {
        if (! is_int($value)) {
            throw InvalidCircuitException::invalidQubitIndex(strtoupper($gate), $value);
        }

        return $value;
    }

    /**
     * Require every value to be an integer qubit index and return them as a list.
     *
     * @param  array<mixed>  $values
     * @return list<int>
     *
     * @throws InvalidCircuitException
     */
    private static function integerIndices(string $gate, array $values): array
    {
        return array_values(array_map(static fn (mixed $value): int => self::integerIndex($gate, $value), $values));
    }

    /**
     * Rebuild a Gate from the flat array shape produced by toArray().
     *
     * Dispatches generically on GateType/GateShape metadata instead of a
     * per-type match arm: qubit-index keys must already be integers, angle
     * keys are checked numeric, in wire order, then make() lays them out and normalises angles.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidCircuitException
     */
    public static function fromArray(array $definition): self
    {
        $type = $definition['type'] ?? null;

        if (! is_string($type)) {
            throw InvalidCircuitException::unknownGateType('');
        }

        $gateType = GateType::tryFrom($type);

        if ($gateType === null) {
            throw InvalidCircuitException::unknownGateType($type);
        }

        if ($gateType === GateType::Measure) {
            return self::measure(self::decodeMeasureTargets($definition));
        }

        $shape = $gateType->shape();
        $qubits = [];
        $angles = [];

        foreach ($shape->qubitKeys() as $key) {
            if (! array_key_exists($key, $definition)) {
                throw InvalidCircuitException::missingGateParameter($type, $key);
            }

            $qubits[] = self::integerIndex($type, $definition[$key]);
        }

        foreach ($shape->angleKeys() as $key) {
            if (! array_key_exists($key, $definition)) {
                throw InvalidCircuitException::missingGateParameter($type, $key);
            }

            $angles[] = self::numericAngle($type, $definition[$key]);
        }

        return self::make($gateType, $qubits, $angles);
    }

    /**
     * Determine whether this gate is a measurement operation.
     */
    public function isMeasurement(): bool
    {
        return $this->type === 'measure';
    }

    /**
     * Return the qubit indices this gate references, in wire order.
     *
     * Measure gates store their targets under the `targets` key, which may
     * be `null` (meaning "all qubits", and therefore nothing to validate).
     * Every other gate type derives its qubit-index keys from
     * GateType::shape()->qubitKeys().
     *
     * @return int[]
     */
    public function qubitIndices(): array
    {
        if ($this->isMeasurement()) {
            return $this->params['targets'] ?? [];
        }

        $keys = GateType::from($this->type)->shape()->qubitKeys();

        return array_map(fn (string $key): int => $this->params[$key], $keys);
    }

    /**
     * Serialize the gate to a flat array suitable for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['type' => $this->type], $this->params);
    }

    /**
     * Decode the `targets` value of a serialized measure gate back into the
     * int[]|null shape expected by measure().
     *
     * @param  array<string, mixed>  $definition
     * @return int[]|null
     */
    private static function decodeMeasureTargets(array $definition): ?array
    {
        $targets = $definition['targets'] ?? null;

        if ($targets === null) {
            return null;
        }

        if (! is_array($targets)) {
            throw InvalidCircuitException::invalidQubitIndex('MEASURE', $targets);
        }

        return self::integerIndices('measure', $targets);
    }

    /**
     * Normalise an angle parameter to a finite float in radians.
     *
     * Angle already rejects non-finite values in its constructor; this guard
     * gives raw floats the same treatment so a NAN or INF fails here with a
     * clear message instead of inside json_encode() in the Python bridge.
     */
    private static function radians(float|Angle $angle): float
    {
        $radians = $angle instanceof Angle ? $angle->radians : $angle;

        if (! is_finite($radians)) {
            throw InvalidCircuitException::nonFiniteAngle($radians);
        }

        return $radians;
    }
}
