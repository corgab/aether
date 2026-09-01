<?php

declare(strict_types=1);

namespace Aether\Tests\Unit\Circuit;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;

/**
 * Test double for a driver that supports cost estimation, used to exercise
 * CircuitBuilder::estimateCost()'s delegation.
 */
final class FakeCostEstimatingDevice implements EstimatesCost, QuantumDevice
{
    public ?int $shotsPassedToEstimateCost = null;

    public ?int $tasksPassedToEstimateCost = null;

    public CostEstimate $estimateToReturn;

    public function __construct()
    {
        $this->estimateToReturn = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);
    }

    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        return new CircuitResult([]);
    }

    public function generateEntropy(int $bits): string
    {
        return str_repeat("\x00", (int) ceil($bits / 8));
    }

    public function estimateCost(int $shots, int $tasks = 1): CostEstimate
    {
        $this->shotsPassedToEstimateCost = $shots;
        $this->tasksPassedToEstimateCost = $tasks;

        return $this->estimateToReturn;
    }
}
