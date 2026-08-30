<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Circuit\CircuitBuilder;
use Aether\Results\BatchResult;

/**
 * Contract for quantum backend drivers that support batch execution.
 */
interface BatchableDevice
{
    /**
     * Execute the given circuits in batch on the device and return the measurement results.
     *
     * @param  list<CircuitBuilder>  $circuits
     */
    public function executeBatch(array $circuits): BatchResult;
}
