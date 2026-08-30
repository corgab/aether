<?php

declare(strict_types=1);

namespace Aether\Circuit;

use Aether\Contracts\BatchableDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;

/**
 * Runs several circuits on one device in a single batch execution.
 */
class BatchBuilder
{
    /**
     * @param  list<CircuitBuilder>  $circuits
     *
     * @throws InvalidCircuitException When a circuit is pinned to a different driver than the batch.
     */
    public function __construct(
        private readonly QuantumDevice $device,
        private readonly array $circuits,
        private readonly string $driverName,
    ) {
        foreach ($circuits as $circuit) {
            $pinnedDriver = $circuit->driverName();

            if ($pinnedDriver !== null && $pinnedDriver !== $driverName) {
                throw InvalidCircuitException::batchDriverMismatch($driverName, $pinnedDriver);
            }
        }
    }

    /**
     * Validate every circuit, then execute the whole batch on the device.
     *
     * @throws QuantumExecutionException When the driver does not implement BatchableDevice.
     * @throws InvalidCircuitException When a circuit has no qubits or no measurement.
     */
    public function run(): BatchResult
    {
        if (! $this->device instanceof BatchableDevice) {
            throw QuantumExecutionException::batchUnsupported($this->driverName);
        }

        foreach ($this->circuits as $circuit) {
            $circuit->validate();
        }

        return $this->device->executeBatch($this->circuits);
    }
}
