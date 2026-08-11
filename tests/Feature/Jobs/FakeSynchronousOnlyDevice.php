<?php

declare(strict_types=1);

namespace Aether\Tests\Feature\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;

/**
 * Test double for a driver that does NOT support asynchronous execution,
 * used to exercise the `asynchronousUnsupported` guard in the async jobs.
 */
final class FakeSynchronousOnlyDevice implements QuantumDevice
{
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        return new CircuitResult([]);
    }

    public function generateEntropy(int $bits): string
    {
        return str_repeat("\x00", (int) ceil($bits / 8));
    }
}
