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
     * Generate random bytes covering the requested bit count.
     *
     * Returns ceil($bits / 8) raw bytes; a bit count that is not a multiple of
     * 8 is rounded up so every byte is fully random rather than zero-padded.
     *
     * The strength of the randomness is whatever the implementing backend
     * measures, not a guarantee of this contract: real quantum hardware
     * yields genuinely random bits, a simulated backend yields pseudorandom
     * ones. Implementations must not present simulated bits as hardware
     * entropy, and callers must rely only on hardware-backed implementations
     * for keys, tokens and nonces.
     */
    public function generateEntropy(int $bits): string;
}
