<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Circuit\CircuitBuilder;
use Aether\Results\CircuitResult;

/**
 * Contract for quantum backend drivers.
 */
interface QuantumDevice
{
    /**
     * Execute the given circuit on the device and return the measurement results.
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult;

    /**
     * Generate cryptographically strong random bytes covering the requested bit count.
     *
     * Returns ceil($bits / 8) raw bytes; a bit count that is not a multiple of
     * 8 is rounded up so every byte is fully random rather than zero-padded.
     */
    public function generateEntropy(int $bits): string;
}
