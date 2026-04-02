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
     * Generate a cryptographically strong random bit-string of the requested length.
     */
    public function generateEntropy(int $bits): string;
}
