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
     * The strength of the randomness is the driver's, not the contract's:
     * measuring qubits on real hardware (the aws driver against a QPU) yields
     * genuinely random bits, while the local simulator and the aws managed
     * simulators produce a classical pseudorandom simulation of the same
     * circuit. Use only hardware-backed entropy for keys, tokens and nonces.
     */
    public function generateEntropy(int $bits): string;
}
