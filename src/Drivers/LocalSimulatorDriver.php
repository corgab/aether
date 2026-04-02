<?php

declare(strict_types=1);

namespace Aether\Drivers;

/**
 * Quantum driver for the local Braket simulator.
 */
class LocalSimulatorDriver extends AbstractQuantumDriver
{
    protected function driverName(): string
    {
        return 'local';
    }
}
