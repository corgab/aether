<?php

declare(strict_types=1);

namespace Aether\Events;

use Aether\Results\CircuitResult;

/**
 * Fired when a quantum circuit finishes executing synchronously via
 * AbstractQuantumDriver::executeCircuit().
 *
 * For the asynchronous path (->dispatch() + polling), see
 * {@see CircuitCompleted} instead — that event fires once the queued task
 * reaches a terminal state, not from this synchronous choke point.
 */
final readonly class CircuitExecuted
{
    /**
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit
     */
    public function __construct(
        public string $driver,
        public array $circuit,
        public CircuitResult $result,
    ) {}
}
