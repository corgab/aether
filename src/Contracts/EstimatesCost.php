<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Results\CostEstimate;

/**
 * Contract for quantum backend drivers that can estimate their own cost
 * from configured pricing, without making any network call.
 */
interface EstimatesCost
{
    /**
     * Estimate the cost of running the given total number of shots across
     * the given number of tasks (1 for a single circuit, or the number of
     * circuits in a batch).
     */
    public function estimateCost(int $shots, int $tasks = 1): CostEstimate;
}
