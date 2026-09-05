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
use Aether\Testing\Concerns\ValidatesCounts;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

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
    use DispatchesLifecycleEvents;
    use ValidatesCounts;

    /**
     * Driver name reported on CircuitExecuted/EntropyGenerated when the fake
     * has no better one to use: no pinned name on the circuit and no driver
     * alias resolved through the manager yet (see resolvedAs()).
     */
    private const FAKE_DRIVER = 'fake';

    /**
     * The driver alias most recently resolved through QuantumManager::driver()
     * while this fake was installed, so events report the same name the real
     * driver would have (Quantum::entropy('aws') reports 'aws', not 'fake').
     */
    private ?string $resolvedDriver = null;

    /** @var array<string, CircuitResult> Task ARN => result resolved on its first successful poll. */
    private array $taskResults = [];

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

    /** @var CircuitStub|null */
    private array|CircuitResult|Closure|ResultSequence|null $circuitStub = null;

    private ?TaskStatus $stubbedTaskStatus = null;

    private ?string $stubbedTaskError = null;

    /** @var CostEstimate|Closure(int, int): CostEstimate|null */
    private CostEstimate|Closure|null $costStub = null;

    /** @var string|Closure(int): (string|null)|null */
    private string|Closure|null $entropyStub = null;

    private int $entropyCounter = 0;

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
     * Remember which driver alias the manager resolved to this fake, so the
     * events it dispatches carry that name instead of the generic 'fake'.
     */
    public function resolvedAs(string $driver): static
    {
        $this->resolvedDriver = $driver;

        return $this;
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
     * instead (optionally with a backend failure reason), for testing
     * polling logic.
     */
    public function checkTask(string $taskArn): TaskSnapshot
    {
        $status = $this->stubbedTaskStatus ?? TaskStatus::Completed;

        if (! $status->isSuccessful()) {
            return new TaskSnapshot($status, null, $this->stubbedTaskError);
        }

        $circuit = $this->tasksByArn[$taskArn] ?? null;

        if ($circuit === null) {
            return new TaskSnapshot($status);
        }

        $this->taskResults[$taskArn] ??= $this->resolveResult($circuit);

        return new TaskSnapshot($status, $this->taskResults[$taskArn]->counts());
    }

    /**
     * The driver name to report on events: the circuit's pinned name, else
     * the alias the manager resolved to this fake, else the generic 'fake'.
     */
    private function driverNameFor(?CircuitBuilder $circuit): string
    {
        return $circuit?->driverName() ?? $this->resolvedDriver ?? self::FAKE_DRIVER;
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

    /**
     * Stub the result returned by executeCircuit(), executeBatch() and
     * checkTask(), overriding the default deterministic 50/50 split.
     *
     * Accepts the same forms as Quantum::fake($stub) — see the class
     * docblock. Calling this again replaces whatever was stubbed before.
     *
     * @param  CircuitStub  $stub
     */
    public function respondWith(array|CircuitResult|Closure|ResultSequence $stub): static
    {
        if (is_array($stub)) {
            self::assertValidCounts($stub);
        }

        $this->circuitStub = $stub;

        return $this;
    }

    /**
     * Stub the measurement counts returned by executeCircuit() and checkTask().
     *
     * Thin, BC-preserving wrapper around respondWith() for the plain counts
     * array form.
     *
     * @param  array<string, int>  $counts
     */
    public function respondWithCounts(array $counts): static
    {
        return $this->respondWith($counts);
    }

    /**
     * Stub the raw bytes returned by generateEntropy(), overriding the
     * default deterministic counter sequence.
     *
     * Pass a fixed byte string (tiled to fill whatever length a given
     * generateEntropy($bits) call needs — use QuantumFake::hex() to build it
     * from a hex string) or a closure receiving the requested bit count and
     * returning the raw bytes for it. A closure returning null falls through
     * to the default counter bytes for that call, matching respondWith()'s
     * closure semantics.
     *
     * @param  string|Closure(int): (string|null)  $entropy
     */
    public function respondEntropyWith(string|Closure $entropy): static
    {
        if ($entropy === '') {
            throw new InvalidArgumentException('Stubbed entropy bytes cannot be an empty string.');
        }

        $this->entropyStub = $entropy;

        return $this;
    }

    /**
     * Stub the status returned by checkTask(), overriding the default
     * Completed status. Use this to simulate a task that is still in
     * flight (e.g. Queued, Running) or that terminated unsuccessfully
     * (Failed, Cancelled), so polling loops and event handling can be
     * exercised in tests.
     *
     * @param  string|null  $error  The backend failure reason to report alongside an
     *                              unsuccessful status, as a real provider would.
     *
     * @throws InvalidArgumentException When a reason is given for a status that is
     *                                  not a failure, since checkTask() would never report it.
     */
    public function respondWithTaskStatus(TaskStatus $status, ?string $error = null): static
    {
        if ($error !== null && ! in_array($status, [TaskStatus::Failed, TaskStatus::Cancelled], true)) {
            throw new InvalidArgumentException(
                "A failure reason can only accompany a Failed or Cancelled task status, [{$status->value}] given."
            );
        }

        $this->stubbedTaskStatus = $status;
        $this->stubbedTaskError = $error;

        return $this;
    }

    /**
     * Stub the estimate returned by estimateCost(), overriding the default
     * free estimate. Pass a fixed CostEstimate, or a closure receiving the
     * requested shot and task counts and returning one.
     *
     * @param  CostEstimate|Closure(int, int): CostEstimate  $estimate
     */
    public function respondCostWith(CostEstimate|Closure $estimate): static
    {
        $this->costStub = $estimate;

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
     * Build a canned CircuitResult from raw counts, for use with
     * Quantum::fake() or respondWith().
     *
     * @param  array<string, int>  $counts
     */
    public static function result(array $counts): CircuitResult
    {
        self::assertValidCounts($counts);

        return new CircuitResult($counts);
    }

    /**
     * Build an ordered sequence of canned results, for use with
     * Quantum::fake() or respondWith(). See ResultSequence for push() /
     * whenEmpty().
     *
     * @param  array<int, array<string, int>|CircuitResult>  $results
     */
    public static function sequence(array $results = []): ResultSequence
    {
        return new ResultSequence($results);
    }

    /**
     * Decode a hex string into the raw bytes respondEntropyWith() expects.
     */
    public static function hex(string $hex): string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException(
                "Invalid hex string [{$hex}]: expected a non-empty, even-length string of hexadecimal digits."
            );
        }

        return (string) hex2bin($hex);
    }

    /**
     * Resolve the result for a circuit that is guaranteed to be known: the
     * stubbed result when one is configured and applies, otherwise a
     * deterministic 50/50 split derived from the circuit's qubit and shot
     * counts.
     */
    private function resolveResult(CircuitBuilder $circuit): CircuitResult
    {
        return $this->evaluateCircuitStub($circuit) ?? $this->deterministicResult($circuit);
    }

    /**
     * Evaluate the configured circuit stub, if any, against a circuit.
     *
     * Returns null both when nothing is stubbed and when a stubbed closure
     * explicitly falls through for this circuit — the caller treats both the
     * same way, by falling back to the deterministic default.
     */
    private function evaluateCircuitStub(?CircuitBuilder $circuit): ?CircuitResult
    {
        return match (true) {
            $this->circuitStub === null => null,
            $this->circuitStub instanceof ResultSequence => $this->toCircuitResult($this->circuitStub->next()),
            $this->circuitStub instanceof Closure => $this->evaluateClosureStub($circuit),
            $this->circuitStub instanceof CircuitResult => $this->circuitStub,
            default => $this->toCircuitResult($this->circuitStub),
        };
    }

    /**
     * Evaluate a closure stub against a circuit, when one is known.
     *
     * A closure stub cannot be evaluated without a circuit to pass it (only
     * checkTask() for an untracked task ARN hits this), so it falls through
     * to the deterministic default there instead of being called with null.
     */
    private function evaluateClosureStub(?CircuitBuilder $circuit): ?CircuitResult
    {
        if ($circuit === null || ! $this->circuitStub instanceof Closure) {
            return null;
        }

        $result = ($this->circuitStub)($circuit);

        return $result === null ? null : $this->toCircuitResult($result);
    }

    /**
     * @param  array<string, int>|CircuitResult  $value
     */
    private function toCircuitResult(array|CircuitResult $value): CircuitResult
    {
        if ($value instanceof CircuitResult) {
            return $value;
        }

        self::assertValidCounts($value);

        return new CircuitResult($value);
    }

    /**
     * Build the deterministic 50/50 split used whenever no stub applies.
     */
    private function deterministicResult(CircuitBuilder $circuit): CircuitResult
    {
        $n = $circuit->qubitCount();
        $shots = $circuit->shotCount();
        $zeros = str_repeat('0', $n);
        $ones = str_repeat('1', $n);
        $half = intdiv($shots, 2);

        return new CircuitResult([
            $zeros => $half,
            $ones => $shots - $half,
        ]);
    }

    /**
     * Resolve the raw bytes for a generateEntropy($bits) call: the stubbed
     * bytes when one is configured and applies, otherwise the deterministic
     * counter sequence.
     */
    private function resolveEntropy(int $bits): string
    {
        $length = (int) ceil($bits / 8);

        if (is_string($this->entropyStub)) {
            return $this->tileBytes($this->entropyStub, $length);
        }

        if ($this->entropyStub instanceof Closure) {
            $bytes = ($this->entropyStub)($bits);

            if ($bytes !== null) {
                $this->assertEntropyLength($bytes, $length, $bits);

                return $bytes;
            }
        }

        return $this->deterministicEntropy($length);
    }

    /**
     * Advance and consume the deterministic counter sequence for $length bytes.
     */
    private function deterministicEntropy(int $length): string
    {
        $bytes = '';

        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($this->entropyCounter++ & 0xFF);
        }

        return $bytes;
    }

    /**
     * Repeat a fixed byte string to fill exactly $length bytes.
     */
    private function tileBytes(string $stub, int $length): string
    {
        return substr(str_repeat($stub, (int) ceil($length / strlen($stub))), 0, $length);
    }

    private function assertEntropyLength(string $bytes, int $expected, int $bits): void
    {
        if (strlen($bytes) !== $expected) {
            $actual = strlen($bytes);

            throw new InvalidArgumentException(
                "Entropy stub closure returned {$actual} byte(s) for a {$bits}-bit request, expected {$expected}."
            );
        }
    }
}
