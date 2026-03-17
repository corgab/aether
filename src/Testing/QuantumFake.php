<?php

declare(strict_types=1);

namespace Aether\Testing;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Test double for QuantumDevice that records interactions.
 */
class QuantumFake implements QuantumDevice
{
    /** @var CircuitBuilder[] */
    private array $recordedCircuits = [];

    /** @var int[] */
    private array $recordedEntropy = [];

    /**
     * Record the circuit and return a deterministic 50/50 result.
     *
     * @param  CircuitBuilder  $circuit
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->recordedCircuits[] = $circuit;

        $n = $circuit->qubitCount();
        $zeros = str_repeat('0', $n);
        $ones = str_repeat('1', $n);

        return new CircuitResult([$zeros => 500, $ones => 500]);
    }

    /**
     * Record the bit request and return deterministic bytes (0xAA pattern).
     *
     * @param  int  $bits
     * @return string
     */
    public function generateEntropy(int $bits): string
    {
        $this->recordedEntropy[] = $bits;

        return str_repeat("\xAA", (int) ceil($bits / 8));
    }

    /**
     * Return whether at least one circuit has been executed.
     */
    public function hasExecutedCircuits(): bool
    {
        return $this->recordedCircuits !== [];
    }

    /**
     * Return all recorded CircuitBuilder instances.
     *
     * @return CircuitBuilder[]
     */
    public function recordedCircuits(): array
    {
        return $this->recordedCircuits;
    }

    /**
     * Return whether at least one entropy generation has been recorded.
     */
    public function hasGeneratedEntropy(): bool
    {
        return $this->recordedEntropy !== [];
    }

    /**
     * Return all recorded bit-lengths passed to generateEntropy().
     *
     * @return int[]
     */
    public function recordedEntropy(): array
    {
        return $this->recordedEntropy;
    }

    /**
     * Assert that at least one circuit was executed, optionally matching a callback.
     *
     * @param  Closure|null  $callback
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
     *
     * @param  int|null  $bits
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
}
