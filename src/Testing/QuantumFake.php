<?php

declare(strict_types=1);

namespace Aether\Testing;

use Aether\Circuit\CircuitBuilder;
use Aether\Concerns\DispatchesLifecycleEvents;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\BatchableDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitExecuted;
use Aether\Events\EntropyGenerated;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Aether\Testing\Concerns\AssertsActivity;
use Aether\Testing\Concerns\RecordsActivity;
use Aether\Testing\Concerns\StubsResponses;
use Aether\Testing\Concerns\ValidatesCounts;
use Closure;

/**
 * Test double for QuantumDevice (and AsynchronousDevice) that records interactions.
 *
 * Also dispatches CircuitExecuted and EntropyGenerated exactly like the real
 * drivers (through the same guarded DispatchesLifecycleEvents trait), so
 * Event::fake() assertions on those events keep working for code under test
 * even when Quantum::fake() stands in for the backend — the same fake/event
 * parity Http::fake() gives Http-driven code.
 *
 * Stubbing follows Http::fake() idioms:
 *
 *   Quantum::fake();                                          // BC: deterministic 50/50 + counter entropy
 *   Quantum::fake(['00' => 700, '11' => 324]);                // canned counts, every circuit
 *   Quantum::fake(QuantumFake::result(['00' => 700]));        // canned CircuitResult
 *   Quantum::fake(fn (CircuitBuilder $c) => $c->qubitCount() === 2 ? ['00' => 1000] : null);
 *   Quantum::fake(QuantumFake::sequence([['0' => 10], ['1' => 10]]));
 *
 * A stub closure returning null falls through to the default deterministic
 * result for that call, exactly like Http::fake()'s closure stubs.
 *
 * The fake also implements EstimatesCost, so application code calling
 * CircuitBuilder::estimateCost() stays testable when the backend is faked:
 * by default every estimate is free (0.00 USD); respondCostWith() stubs a
 * specific CostEstimate or a closure computing one.
 *
 * @phpstan-type CircuitStub array<string, int>|CircuitResult|Closure(CircuitBuilder): (array<string, int>|CircuitResult|null)|ResultSequence
 */
class QuantumFake implements AsynchronousDevice, BatchableDevice, EstimatesCost, QuantumDevice
{
    use AssertsActivity;
    use DispatchesLifecycleEvents;
    use RecordsActivity;
    use StubsResponses;
    use ValidatesCounts;

    /**
     * @param  CircuitStub|null  $stub  Optional canned response applied to every circuit
     *                                  executed through this fake (see the class docblock for
     *                                  the accepted forms). Omit for the plain deterministic
     *                                  behaviour, unchanged from before stubbing existed.
     */
    public function __construct(array|CircuitResult|Closure|ResultSequence|null $stub = null)
    {
        if ($stub !== null) {
            $this->respondWith($stub);
        }
    }

    /**
     * Record the circuit and return its stubbed result, or a deterministic
     * 50/50 split when nothing was stubbed for it.
     */
    public function executeCircuit(CircuitBuilder $circuit): CircuitResult
    {
        $this->recordedCircuits[] = $circuit;

        $result = $this->resolveResult($circuit);

        $this->dispatchEvent(new CircuitExecuted($this->driverNameFor($circuit), $circuit->toArray(), $result));

        return $result;
    }

    /**
     * Record the batch and return one fake result per circuit. Every circuit
     * is also recorded individually and announced with its own
     * CircuitExecuted, so circuit-level assertions and listeners see batched
     * executions exactly like single ones. Each circuit resolves its own stub
     * independently — a ResultSequence advances once per circuit.
     *
     * @param  CircuitBuilder[]  $circuits
     */
    public function executeBatch(array $circuits): BatchResult
    {
        $this->recordedBatches[] = $circuits;

        $results = [];

        foreach ($circuits as $circuit) {
            $this->recordedCircuits[] = $circuit;
            $result = $this->resolveResult($circuit);
            $results[] = $result;

            $this->dispatchEvent(new CircuitExecuted($this->driverNameFor($circuit), $circuit->toArray(), $result));
        }

        return new BatchResult($results);
    }

    /**
     * Record the bit request and return the stubbed entropy bytes, or a
     * deterministic counter sequence when nothing was stubbed.
     *
     * The counter-based default keeps advancing across calls rather than
     * repeating a constant. A repeating byte makes the bitstring periodic,
     * and EntropyGenerator::integer() rejection-samples fixed-width chunks of
     * it — with a periodic source every chunk carries the same value, so any
     * range that rejects that value rejects every chunk and the generator
     * exhausts itself instead of returning. respondEntropyWith() lets a test
     * opt into a fixed or periodic byte stream anyway; that trade-off is then
     * the caller's choice, not the default.
     */
    public function generateEntropy(int $bits): string
    {
        $this->recordedEntropy[] = $bits;

        $bytes = $this->resolveEntropy($bits);

        $this->dispatchEvent(new EntropyGenerated($this->driverNameFor(null), $bits));

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
     * Defaults to Completed with the same result executeCircuit() would
     * produce for the submitted circuit (stubbed or deterministic). The
     * result is resolved on the first successful poll and kept for the task,
     * so repeated polling of one task consumes a single ResultSequence entry
     * and always reports the same counts — like a real completed task. Use
     * respondWithTaskStatus() to simulate a non-terminal or failed status
     * instead, for testing polling logic.
     */
    public function checkTask(string $taskArn): TaskSnapshot
    {
        $status = $this->stubbedTaskStatus ?? TaskStatus::Completed;

        if (! $status->isSuccessful()) {
            return new TaskSnapshot($status);
        }

        $circuit = $this->tasksByArn[$taskArn] ?? null;

        if ($circuit === null) {
            return new TaskSnapshot($status);
        }

        $this->taskResults[$taskArn] ??= $this->resolveResult($circuit);

        return new TaskSnapshot($status, $this->taskResults[$taskArn]->counts());
    }

    /**
     * Return the stubbed cost estimate, or a free (0.00 USD) one when nothing
     * was stubbed, so code paths that budget a circuit before running it stay
     * testable through the fake.
     */
    public function estimateCost(int $shots, int $tasks = 1): CostEstimate
    {
        if ($this->costStub instanceof Closure) {
            return ($this->costStub)($shots, $tasks);
        }

        return $this->costStub ?? new CostEstimate(
            amount: 0.0,
            currency: 'USD',
            shots: $shots,
            breakdown: ['per_task' => 0.0, 'per_shot' => 0.0],
        );
    }
}
