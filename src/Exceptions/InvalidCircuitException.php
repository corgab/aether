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
     */
    public static function gateTargetOutOfRange(string $gate, int $target, int $qubitCount): self
    {
        return new self(
            "Gate [{$gate}] targets qubit {$target}, but the circuit only has {$qubitCount} qubit(s) (valid indices: 0–".($qubitCount - 1).').'
        );
    }

    /**
     * Create an exception for an invalid qubit count.
     */
    public static function invalidQubitCount(int $count): self
    {
        return new self("The circuit requires at least 1 qubit, {$count} given.");
    }

    /**
     * Create an exception for an invalid shot count.
     */
    public static function invalidShotCount(int $shots): self
    {
        return new self("The circuit requires at least 1 shot, {$shots} given.");
    }

    /**
     * Create an exception for a circuit that has no measurement operations.
     */
    public static function noMeasurement(): self
    {
        return new self('The circuit has no measurement operations. Add at least one measurement before running.');
    }

    /**
     * Create an exception for a qubit count reduction that would leave an
     * already-added gate targeting a qubit index outside the new range.
     */
    public static function qubitCountBelowExistingGates(string $gate, int $target, int $count): self
    {
        return new self(
            "Cannot set qubit count to {$count}: gate [{$gate}] already targets qubit {$target}, ".
            'which would be out of range. Remove or update the offending gate before shrinking the circuit.'
        );
    }

    /**
     * Create an exception for a measurement operation with an empty target list.
     */
    public static function emptyMeasurementTargets(): self
    {
        return new self(
            'The measure() targets array cannot be empty. Pass null to measure all qubits, an int for a single qubit, or a non-empty array of qubit indices.'
        );
    }

    /**
     * Create an exception for an unrecognized gate type encountered while
     * rebuilding a circuit from its array definition (see CircuitBuilder::fromArray()).
     */
    public static function unknownGateType(string $type): self
    {
        return new self(
            "Unknown gate type [{$type}] encountered while rebuilding a circuit from its array definition."
        );
    }

    /**
     * Create an exception when a circuit in a batch has a driver pinned that does not match the batch driver.
     */
    public static function batchDriverMismatch(string $expected, string $actual): self
    {
        return new self(
            "Batch is targeting driver [{$expected}], but circuit is pinned to driver [{$actual}]."
        );
    }
}
