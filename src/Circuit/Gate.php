<?php

declare(strict_types=1);

namespace Aether\Circuit;

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
     * Create a Phase-S gate on the given qubit.
     */
    public static function s(int $target): self
    {
        return new self('s', ['target' => $target]);
    }

    /**
     * Create a Phase-T gate on the given qubit.
     */
    public static function t(int $target): self
    {
        return new self('t', ['target' => $target]);
    }

    /**
     * Create a rotation around the X-axis.
     */
    public static function rx(int $target, float|Angle $angle): self
    {
        return new self('rx', [
            'target' => $target,
            'angle' => $angle instanceof Angle ? $angle->radians : $angle,
        ]);
    }

    /**
     * Create a rotation around the Y-axis.
     */
    public static function ry(int $target, float|Angle $angle): self
    {
        return new self('ry', [
            'target' => $target,
            'angle' => $angle instanceof Angle ? $angle->radians : $angle,
        ]);
    }

    /**
     * Create a rotation around the Z-axis.
     */
    public static function rz(int $target, float|Angle $angle): self
    {
        return new self('rz', [
            'target' => $target,
            'angle' => $angle instanceof Angle ? $angle->radians : $angle,
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
     * Create a SWAP gate.
     */
    public static function swap(int $target0, int $target1): self
    {
        return new self('swap', ['target0' => $target0, 'target1' => $target1]);
    }

    /**
     * Create a Toffoli (CCNOT) gate.
     */
    public static function ccnot(int $control0, int $control1, int $target): self
    {
        return new self('ccnot', ['control0' => $control0, 'control1' => $control1, 'target' => $target]);
    }

    /**
     * Create a measurement gate.
     *
     * @param  int|int[]|null  $targets
     */
    public static function measure(int|array|null $targets = null): self
    {
        $resolved = match (true) {
            $targets === null => null,
            is_int($targets) => [$targets],
            default => $targets,
        };

        return new self('measure', ['targets' => $resolved]);
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
}
