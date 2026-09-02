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
     * Create a Hadamard gate on the given qubit.
     */
    public static function h(int $target): self
    {
        return new self('h', ['target' => $target]);
    }

    /**
     * Create a Pauli-X (NOT) gate on the given qubit.
     */
    public static function x(int $target): self
    {
        return new self('x', ['target' => $target]);
    }

    /**
     * Create a Pauli-Y gate on the given qubit.
     */
    public static function y(int $target): self
    {
        return new self('y', ['target' => $target]);
    }

    /**
     * Create a Pauli-Z gate on the given qubit.
     */
    public static function z(int $target): self
    {
        return new self('z', ['target' => $target]);
    }

    /**
     * Create an Identity gate on the given qubit.
     */
    public static function i(int $target): self
    {
        return new self('i', ['target' => $target]);
    }

    /**
     * Create a Phase-S gate on the given qubit.
     */
    public static function s(int $target): self
    {
        return new self('s', ['target' => $target]);
    }

    /**
     * Create a Phase-S† (adjoint S) gate on the given qubit.
     */
    public static function si(int $target): self
    {
        return new self('si', ['target' => $target]);
    }

    /**
     * Create a Phase-T gate on the given qubit.
     */
    public static function t(int $target): self
    {
        return new self('t', ['target' => $target]);
    }

    /**
     * Create a Phase-T† (adjoint T) gate on the given qubit.
     */
    public static function ti(int $target): self
    {
        return new self('ti', ['target' => $target]);
    }

    /**
     * Create a rotation around the X-axis.
     */
    public static function rx(int $target, float|Angle $angle): self
    {
        return new self('rx', [
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a rotation around the Y-axis.
     */
    public static function ry(int $target, float|Angle $angle): self
    {
        return new self('ry', [
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a rotation around the Z-axis.
     */
    public static function rz(int $target, float|Angle $angle): self
    {
        return new self('rz', [
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a Controlled-NOT gate.
     */
    public static function cnot(int $control, int $target): self
    {
        return new self('cnot', ['control' => $control, 'target' => $target]);
    }

    /**
     * Create a Controlled-Z gate.
     */
    public static function cz(int $control, int $target): self
    {
        return new self('cz', ['control' => $control, 'target' => $target]);
    }

    /**
     * Create a Controlled-Y gate.
     */
    public static function cy(int $control, int $target): self
    {
        return new self('cy', ['control' => $control, 'target' => $target]);
    }

    /**
     * Create a SWAP gate.
     */
    public static function swap(int $qubit0, int $qubit1): self
    {
        return new self('swap', ['target0' => $qubit0, 'target1' => $qubit1]);
    }

    /**
     * Create a Toffoli (CCNOT) gate.
     */
    public static function ccnot(int $control0, int $control1, int $target): self
    {
        return new self('ccnot', ['control0' => $control0, 'control1' => $control1, 'target' => $target]);
    }

    /**
     * Create a Controlled-RX gate.
     */
    public static function crx(int $control, int $target, float|Angle $angle): self
    {
        return new self('crx', [
            'control' => $control,
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a Controlled-RY gate.
     */
    public static function cry(int $control, int $target, float|Angle $angle): self
    {
        return new self('cry', [
            'control' => $control,
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a Controlled-RZ gate.
     */
    public static function crz(int $control, int $target, float|Angle $angle): self
    {
        return new self('crz', [
            'control' => $control,
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a Controlled-PhaseShift gate.
     */
    public static function cphaseshift(int $control, int $target, float|Angle $angle): self
    {
        return new self('cphaseshift', [
            'control' => $control,
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a PhaseShift gate.
     */
    public static function phaseshift(int $target, float|Angle $angle): self
    {
        return new self('phaseshift', [
            'target' => $target,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a U gate.
     */
    public static function u(int $target, float|Angle $theta, float|Angle $phi, float|Angle $lambda): self
    {
        return new self('u', [
            'target' => $target,
            'theta' => self::radians($theta),
            'phi' => self::radians($phi),
            'lambda' => self::radians($lambda),
        ]);
    }

    /**
     * Create a Controlled-SWAP (Fredkin) gate.
     */
    public static function cswap(int $control, int $qubit0, int $qubit1): self
    {
        return new self('cswap', [
            'control' => $control,
            'target0' => $qubit0,
            'target1' => $qubit1,
        ]);
    }

    /**
     * Create an iSWAP gate.
     */
    public static function iswap(int $qubit0, int $qubit1): self
    {
        return new self('iswap', ['target0' => $qubit0, 'target1' => $qubit1]);
    }

    /**
     * Create an XX gate.
     */
    public static function xx(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return new self('xx', [
            'target0' => $qubit0,
            'target1' => $qubit1,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a YY gate.
     */
    public static function yy(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return new self('yy', [
            'target0' => $qubit0,
            'target1' => $qubit1,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a ZZ gate.
     */
    public static function zz(int $qubit0, int $qubit1, float|Angle $angle): self
    {
        return new self('zz', [
            'target0' => $qubit0,
            'target1' => $qubit1,
            'angle' => self::radians($angle),
        ]);
    }

    /**
     * Create a measurement gate.
     *
     * - Pass null (default) to measure all qubits.
     * - Pass an int to measure a single qubit.
     * - Pass a non-empty array to measure the specified qubits.
     *
     * The empty-targets guard lives here rather than in CircuitBuilder so
     * every construction path — the fluent measure(), fromArray() on a
     * queued definition, or a fragment appended from another builder —
     * rejects a measurement that would measure nothing.
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
            default => $targets,
        };

        return new self('measure', ['targets' => $resolved]);
    }

    /**
     * Rebuild a Gate from the flat array shape produced by toArray().
     *
     * Dispatches generically on GateType/GateShape metadata instead of a
     * per-type match arm: qubit-index keys are cast to int, angle keys are
     * cast to float and normalised via radians(), in wire order.
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
        $params = [];

        foreach ($shape->qubitKeys() as $key) {
            if (! array_key_exists($key, $definition)) {
                throw InvalidCircuitException::missingGateParameter($type, $key);
            }

            $params[$key] = (int) $definition[$key];
        }

        foreach ($shape->angleKeys() as $key) {
            if (! array_key_exists($key, $definition)) {
                throw InvalidCircuitException::missingGateParameter($type, $key);
            }

            $params[$key] = self::radians((float) $definition[$key]);
        }

        return new self($type, $params);
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

        return array_map(fn (string $key): int => (int) $this->params[$key], $keys);
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

        if (! is_array($targets)) {
            return null;
        }

        return array_map(static fn (mixed $target): int => (int) $target, $targets);
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
