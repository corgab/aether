<?php

declare(strict_types=1);

namespace Aether\Testing;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\BatchableDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Test double for QuantumDevice (and AsynchronousDevice) that records interactions.
 */
class QuantumFake implements AsynchronousDevice, BatchableDevice, QuantumDevice
{
    /** @var CircuitBuilder[] */
    private array $recordedCircuits = [];

    /** @var array<int, CircuitBuilder[]> */
    private array $recordedBatches = [];

    /** @var int[] */
    private array $recordedEntropy = [];

    /** @var CircuitBuilder[] */
    private array $dispatchedCircuits = [];

    /** @var array<string, CircuitBuilder> Task ARN => the circuit it was submitted for. */
    private array $tasksByArn = [];

    private int $dispatchCounter = 0;

    /** @var array<string, int>|null */
    private ?array $stubbedCounts = null;

    private ?TaskStatus $stubbedTaskStatus = null;

    private int $entropyCounter = 0;

    /**
     * Record the circuit and return a deterministic 50/50 result (or the
     * counts stubbed via respondWithCounts()).
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->recordedCircuits[] = $circuit;

        return new CircuitResult($this->resolveCounts($circuit));
    }

    /**
     * Record the batch and return one fake result per circuit. Every circuit
     * is also recorded individually, so the circuit-level assertions see
     * batched executions exactly like single ones.
     *
     * @param  CircuitBuilder[]  $circuits
     */
    public function executeBatch(array $circuits): BatchResult
    {
        $this->recordedBatches[] = $circuits;

        $results = [];

        foreach ($circuits as $circuit) {
            $this->recordedCircuits[] = $circuit;
            $results[] = new CircuitResult($this->resolveCounts($circuit));
        }

        return new BatchResult($results);
    }

    /**
     * Record the bit request and return deterministic bytes.
     *
     * The bytes come from a counter that keeps advancing across calls rather
     * than a repeated constant. A repeating byte makes the bitstring periodic,
     * and EntropyGenerator::integer() rejection-samples fixed-width chunks of
     * it — with a periodic source every chunk carries the same value, so any
     * range that rejects that value rejects every chunk and the generator
     * exhausts itself instead of returning.
     */
    public function generateEntropy(int $bits): string
    {
        $this->recordedEntropy[] = $bits;

        $bytes = '';

        for ($i = 0, $length = (int) ceil($bits / 8); $i < $length; $i++) {
            $bytes .= chr($this->entropyCounter++ & 0xFF);
        }

        return $bytes;
    }

    /**
     * Record the circuit as dispatched (distinct from executed circuits) and
     * return a deterministic, incrementing fake task ARN.
     */
    public function submitCircuit(CircuitBuilder $circuit): string
    {
        $this->dispatchedCircuits[] = $circuit;
        $this->dispatchCounter++;

        $arn = "arn:aws:braket:::fake-task/{$this->dispatchCounter}";
        $this->tasksByArn[$arn] = $circuit;

        return $arn;
    }

    /**
     * Return a deterministic snapshot for the given task.
     *
     * Defaults to Completed with the same counts executeCircuit() would
     * produce for the submitted circuit (or the counts stubbed via
     * respondWithCounts()). Use respondWithTaskStatus() to simulate a
     * non-terminal or failed status instead, for testing polling logic.
     */
    public function checkTask(string $taskArn): TaskSnapshot
    {
        $status = $this->stubbedTaskStatus ?? TaskStatus::Completed;

        if (! $status->isSuccessful()) {
            return new TaskSnapshot($status);
        }

        $circuit = $this->tasksByArn[$taskArn] ?? null;
        $counts = $this->stubbedCounts ?? ($circuit !== null ? $this->resolveCounts($circuit) : null);

        return new TaskSnapshot($status, $counts);
    }

    /**
     * Stub the measurement counts returned by executeCircuit() and checkTask(),
     * overriding the default deterministic 50/50 split.
     *
     * @param  array<string, int>  $counts
     */
    public function respondWithCounts(array $counts): static
    {
        $this->stubbedCounts = $counts;

        return $this;
    }

    /**
     * Stub the status returned by checkTask(), overriding the default
     * Completed status. Use this to simulate a task that is still in
     * flight (e.g. Queued, Running) or that terminated unsuccessfully
     * (Failed, Cancelled), so polling loops and event handling can be
     * exercised in tests.
     */
    public function respondWithTaskStatus(TaskStatus $status): static
    {
        $this->stubbedTaskStatus = $status;

        return $this;
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
     * Return all CircuitBuilder instances submitted via submitCircuit().
     *
     * @return CircuitBuilder[]
     */
    public function dispatchedCircuits(): array
    {
        return $this->dispatchedCircuits;
    }

    /**
     * Return all recorded batches, each as the list of CircuitBuilder instances it contained.
     *
     * @return array<int, CircuitBuilder[]>
     */
    public function recordedBatches(): array
    {
        return $this->recordedBatches;
    }

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

    /**
     * Resolve the measurement counts for a circuit: the stubbed counts set
     * via respondWithCounts() when present, otherwise a deterministic 50/50
     * split derived from the circuit's qubit and shot counts.
     *
     * @return array<string, int>
     */
    private function resolveCounts(CircuitBuilder $circuit): array
    {
        if ($this->stubbedCounts !== null) {
            return $this->stubbedCounts;
        }

        $n = $circuit->qubitCount();
        $shots = $circuit->shotCount();
        $zeros = str_repeat('0', $n);
        $ones = str_repeat('1', $n);
        $half = intdiv($shots, 2);

        return [
            $zeros => $half,
            $ones => $shots - $half,
        ];
    }
}
