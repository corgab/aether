<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a quantum circuit definition is structurally invalid.
 */
class InvalidCircuitException extends AetherException
{
    /**
     * Create an exception for a circuit that has no qubits defined.
     */
    public static function noQubits(): self
    {
        return new self('The circuit must have at least one qubit before gates can be applied.');
    }

    /**
     * Create an exception for a gate applied to a qubit index outside the circuit range.
     *
     * @param  string  $gate
     * @param  int  $target
     * @param  int  $qubitCount
     */
    public static function gateTargetOutOfRange(string $gate, int $target, int $qubitCount): self
    {
        return new self(
            "Gate [{$gate}] targets qubit {$target}, but the circuit only has {$qubitCount} qubit(s) (valid indices: 0–" . ($qubitCount - 1) . ').'
        );
    }

    /**
     * Create an exception for a circuit that has no measurement operations.
     */
    public static function noMeasurement(): self
    {
        return new self('The circuit has no measurement operations. Add at least one measurement before running.');
    }
}
