<?php

declare(strict_types=1);

namespace Aether\Testing\Concerns;

use Aether\Circuit\CircuitBuilder;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskStatus;
use Aether\Testing\ResultSequence;
use Closure;
use InvalidArgumentException;

/**
 * @phpstan-type CircuitStub array<string, int>|CircuitResult|Closure(CircuitBuilder): (array<string, int>|CircuitResult|null)|ResultSequence
 */
trait StubsResponses
{
    /** @var array<string, CircuitResult> Task ARN => result resolved on its first successful poll. */
    protected array $taskResults = [];

    /** @var CircuitStub|null */
    protected array|CircuitResult|Closure|ResultSequence|null $circuitStub = null;

    protected ?TaskStatus $stubbedTaskStatus = null;

    /** @var CostEstimate|Closure(int, int): CostEstimate|null */
    protected CostEstimate|Closure|null $costStub = null;

    /** @var string|Closure(int): (string|null)|null */
    protected string|Closure|null $entropyStub = null;

    protected int $entropyCounter = 0;

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
     */
    public function respondWithTaskStatus(TaskStatus $status): static
    {
        $this->stubbedTaskStatus = $status;

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
    protected function resolveResult(CircuitBuilder $circuit): CircuitResult
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
    protected function evaluateCircuitStub(?CircuitBuilder $circuit): ?CircuitResult
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
    protected function evaluateClosureStub(?CircuitBuilder $circuit): ?CircuitResult
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
    protected function toCircuitResult(array|CircuitResult $value): CircuitResult
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
    protected function deterministicResult(CircuitBuilder $circuit): CircuitResult
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
    protected function resolveEntropy(int $bits): string
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
    protected function deterministicEntropy(int $length): string
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
    protected function tileBytes(string $stub, int $length): string
    {
        return substr(str_repeat($stub, (int) ceil($length / strlen($stub))), 0, $length);
    }

    protected function assertEntropyLength(string $bytes, int $expected, int $bits): void
    {
        if (strlen($bytes) !== $expected) {
            $actual = strlen($bytes);

            throw new InvalidArgumentException(
                "Entropy stub closure returned {$actual} byte(s) for a {$bits}-bit request, expected {$expected}."
            );
        }
    }
}
