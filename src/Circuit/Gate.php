<?php

declare(strict_types=1);

namespace Aether\Circuit;

/**
 * Immutable value object representing a single quantum gate operation.
 */
final class Gate
{
    /**
     * @param  int[]|null  $targets
     */
    private function __construct(
        public readonly string $type,
        public readonly ?int $target,
        public readonly ?int $control,
        public readonly ?array $targets,
    ) {}

    /**
     * Create a Hadamard gate on the given qubit.
     */
    public static function h(int $target): self
    {
        return new self(type: 'h', target: $target, control: null, targets: null);
    }

    /**
     * Create a Pauli-X (NOT) gate on the given qubit.
     */
    public static function x(int $target): self
    {
        return new self(type: 'x', target: $target, control: null, targets: null);
    }

    /**
     * Create a Pauli-Y gate on the given qubit.
     */
    public static function y(int $target): self
    {
        return new self(type: 'y', target: $target, control: null, targets: null);
    }

    /**
     * Create a Pauli-Z gate on the given qubit.
     */
    public static function z(int $target): self
    {
        return new self(type: 'z', target: $target, control: null, targets: null);
    }

    /**
     * Create a Controlled-NOT gate.
     */
    public static function cnot(int $control, int $target): self
    {
        return new self(type: 'cnot', target: $target, control: $control, targets: null);
    }

    /**
     * Create a measurement gate.
     *
     * - Pass null  → measure all qubits (targets remains null).
     * - Pass int   → measure a single qubit (wrapped into a one-element array).
     * - Pass array → measure the specified qubits verbatim.
     *
     * @param  int|int[]|null  $targets
     */
    public static function measure(int|array|null $targets): self
    {
        $resolved = match (true) {
            $targets === null => null,
            is_int($targets) => [$targets],
            default => $targets,
        };

        return new self(type: 'measure', target: null, control: null, targets: $resolved);
    }

    /**
     * Serialize the gate to a plain array suitable for JSON encoding or
     * passing to a circuit runner.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === 'measure') {
            return ['type' => 'measure', 'targets' => $this->targets];
        }

        if ($this->type === 'cnot') {
            return ['type' => 'cnot', 'control' => $this->control, 'target' => $this->target];
        }

        return ['type' => $this->type, 'target' => $this->target];
    }
}
