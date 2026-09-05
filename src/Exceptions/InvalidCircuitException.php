<?php

declare(strict_types=1);

namespace Aether\Exceptions;

use Aether\Results\CostEstimate;

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
     * Create an exception for appending a circuit that requires more qubits than are available.
     */
    public static function appendedCircuitTooLarge(int $fragmentQubits, int $qubits): self
    {
        return new self(
            "Cannot append a fragment requiring {$fragmentQubits} qubits into a circuit with only {$qubits} qubits."
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

    /**
     * Create an exception for a gate definition missing a required parameter
     * key while being rebuilt from its array shape (see Gate::fromArray()).
     */
    public static function missingGateParameter(string $type, string $key): self
    {
        return new self(
            "Gate [{$type}] is missing required parameter [{$key}] in its array definition."
        );
    }

    /**
     * Create an exception for a circuit requesting more qubits than the
     * driver's configured `max_qubits` ceiling allows.
     */
    public static function qubitCeilingExceeded(int $requested, int $ceiling, string $driver): self
    {
        return new self(
            "Circuit requires {$requested} qubit(s), which exceeds the [{$driver}] driver's configured ".
            "max_qubits ceiling of {$ceiling}. Statevector simulation memory doubles with every additional ".
            'qubit, so this ceiling protects the host from exhausting its memory. Raise the `max_qubits` '.
            "entry in the driver's config (e.g. the AETHER_MAX_QUBITS env var for the local driver) if the ".
            'host has enough memory to spare, or set it to null to remove the ceiling entirely.'
        );
    }

    /**
     * Create an exception for a circuit (or batch) whose estimated cost
     * exceeds the driver's configured `max_cost_per_run` ceiling.
     */
    public static function costCeilingExceeded(CostEstimate $estimate, float $ceiling): self
    {
        return new self(
            "Estimated cost of {$estimate} exceeds the configured max_cost_per_run ceiling of ".
            CostEstimate::formatAmount($ceiling, $estimate->currency).'. Raise the `max_cost_per_run` entry in the '.
            'driver\'s config (e.g. the AETHER_AWS_MAX_COST env var), or reduce the shot/task count, before retrying.'
        );
    }

    /**
     * Create an exception for an entropy request that the driver's admission
     * checks (qubit or cost ceiling) rejected, wrapping the underlying
     * ceiling exception so the entropy-specific remedy is spelled out: the
     * circuit width comes from `entropy_qubits`, the shot count from the
     * requested bits.
     */
    public static function entropyRejected(int $bits, int $qubits, int $shots, self $previous): self
    {
        return new self(
            "Entropy generation of {$bits} bit(s), a {$qubits}-qubit circuit run for {$shots} shot(s), was rejected: ".
            $previous->getMessage().
            ' For entropy generation, set `entropy_qubits` to a positive value that fits `max_qubits`, and '.
            'request fewer bits per call to lower the estimated cost (each call is one task with '.
            'ceil(bits / entropy_qubits) shots).',
            0,
            $previous,
        );
    }

    /**
     * Create an exception for a gate angle that is NAN or infinite.
     */
    public static function nonFiniteAngle(float $angle): self
    {
        $given = is_nan($angle) ? 'NAN' : ($angle > 0 ? 'INF' : '-INF');

        return new self("Gate angles must be finite floats, {$given} given.");
    }
}
