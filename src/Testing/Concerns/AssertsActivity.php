<?php

declare(strict_types=1);

namespace Aether\Testing\Concerns;

use Closure;
use PHPUnit\Framework\Assert;

trait AssertsActivity
{
    /**
     * Assert that at least one circuit was executed, optionally matching a callback.
     */
    public function assertCircuitRan(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->recordedCircuits,
            'No circuits were executed.',
        );

        if ($callback !== null) {
            $matched = array_filter($this->recordedCircuits, $callback);

            Assert::assertNotEmpty(
                $matched,
                'No recorded circuit matched the given callback.',
            );
        }
    }

    /**
     * Assert that entropy was generated, optionally for a specific bit count.
     */
    public function assertEntropyGenerated(?int $bits = null): void
    {
        Assert::assertNotEmpty(
            $this->recordedEntropy,
            'No entropy was generated.',
        );

        if ($bits !== null) {
            Assert::assertContains(
                $bits,
                $this->recordedEntropy,
                "No entropy generation was recorded for {$bits} bits.",
            );
        }
    }

    /**
     * Assert that no circuits were executed.
     */
    public function assertCircuitNotRan(): void
    {
        Assert::assertEmpty(
            $this->recordedCircuits,
            'Unexpected circuits were executed.',
        );
    }

    /**
     * Assert that no entropy was generated.
     */
    public function assertEntropyNotGenerated(): void
    {
        Assert::assertEmpty(
            $this->recordedEntropy,
            'Unexpected entropy was generated.',
        );
    }

    /**
     * Assert that exactly the given number of circuits were executed.
     */
    public function assertCircuitRanTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recordedCircuits,
            "Expected {$count} circuit(s) to be executed, got ".count($this->recordedCircuits).'.',
        );
    }

    /**
     * Assert that exactly the given number of entropy generations were recorded.
     */
    public function assertEntropyGeneratedTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recordedEntropy,
            "Expected {$count} entropy generation(s), got ".count($this->recordedEntropy).'.',
        );
    }

    /**
     * Assert that at least one circuit was dispatched, optionally matching a callback.
     */
    public function assertCircuitDispatched(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->dispatchedCircuits,
            'No circuits were dispatched.',
        );

        if ($callback !== null) {
            $matched = array_filter($this->dispatchedCircuits, $callback);

            Assert::assertNotEmpty(
                $matched,
                'No dispatched circuit matched the given callback.',
            );
        }
    }

    /**
     * Assert that no circuits were dispatched.
     */
    public function assertCircuitNotDispatched(): void
    {
        Assert::assertEmpty(
            $this->dispatchedCircuits,
            'Unexpected circuits were dispatched.',
        );
    }

    /**
     * Assert that exactly the given number of circuits were dispatched.
     */
    public function assertCircuitDispatchedTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->dispatchedCircuits,
            "Expected {$count} circuit(s) to be dispatched, got ".count($this->dispatchedCircuits).'.',
        );
    }

    /**
     * Assert that at least one batch was executed, optionally matching a callback
     * that receives the list of CircuitBuilder instances in the batch.
     */
    public function assertBatchRan(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->recordedBatches,
            'No batches were executed.',
        );

        if ($callback !== null) {
            $matched = array_filter($this->recordedBatches, $callback);

            Assert::assertNotEmpty(
                $matched,
                'No recorded batch matched the given callback.',
            );
        }
    }

    /**
     * Assert that no batches were executed.
     */
    public function assertBatchNotRan(): void
    {
        Assert::assertEmpty(
            $this->recordedBatches,
            'Unexpected batches were executed.',
        );
    }

    /**
     * Assert that exactly the given number of batches were executed.
     */
    public function assertBatchRanTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->recordedBatches,
            "Expected {$count} batch(es) to be executed, got ".count($this->recordedBatches).'.',
        );
    }
}
