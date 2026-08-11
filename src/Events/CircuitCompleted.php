<?php

declare(strict_types=1);

namespace Aether\Events;

use Aether\Results\CircuitResult;

/**
 * Fired when an asynchronously dispatched quantum circuit finishes executing.
 */
final readonly class CircuitCompleted
{
    /**
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit
     */
    public function __construct(
        public string $driver,
        public array $circuit,
        public CircuitResult $result,
        public ?string $taskArn = null,
    ) {}
}
