<?php

declare(strict_types=1);

namespace Aether\Testing;

use Aether\Results\CircuitResult;
use Aether\Testing\Concerns\ValidatesCounts;
use OutOfBoundsException;

/**
 * An ordered queue of canned circuit results, consumed one per resolution.
 *
 * Mirrors Illuminate\Http\Client\Factory's request sequences: push() enqueues
 * outcomes in order, each resolution shifts the next one off the front, and
 * exhausting the queue throws an OutOfBoundsException unless whenEmpty()
 * configured a fallback — the same Http::sequence() parity QuantumFake gives
 * the rest of Http::fake()'s stub API.
 *
 * Build one with QuantumFake::sequence() and pass it to Quantum::fake().
 */
final class ResultSequence
{
    use ValidatesCounts;

    /** @var array<int, array<string, int>|CircuitResult> */
    private array $queue = [];

    /** @var array<string, int>|CircuitResult|null */
    private array|CircuitResult|null $fallback = null;

    /**
     * @param  array<int, array<string, int>|CircuitResult>  $results
     */
    public function __construct(array $results = [])
    {
        foreach ($results as $result) {
            $this->push($result);
        }
    }

    /**
     * Enqueue another result at the back of the sequence.
     *
     * @param  array<string, int>|CircuitResult  $result
     */
    public function push(array|CircuitResult $result): static
    {
        if (is_array($result)) {
            self::assertValidCounts($result);
        }

        $this->queue[] = $result;

        return $this;
    }

    /**
     * Configure a fallback result to keep returning once the sequence is
     * exhausted, instead of throwing.
     *
     * @param  array<string, int>|CircuitResult  $result
     */
    public function whenEmpty(array|CircuitResult $result): static
    {
        if (is_array($result)) {
            self::assertValidCounts($result);
        }

        $this->fallback = $result;

        return $this;
    }

    /**
     * Return whether the queue has no more pushed results left. A configured
     * whenEmpty() fallback does not count — it only kicks in once this is true.
     */
    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    /**
     * Shift the next result off the queue, or return the whenEmpty()
     * fallback once exhausted.
     *
     * @return array<string, int>|CircuitResult
     *
     * @throws OutOfBoundsException when the queue is exhausted and no
     *                              whenEmpty() fallback was configured.
     */
    public function next(): array|CircuitResult
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        if ($this->fallback !== null) {
            return $this->fallback;
        }

        throw new OutOfBoundsException(
            'The stubbed result sequence is empty. Add more results with push(), or call whenEmpty() '.
            'to return a fallback result instead of throwing.'
        );
    }
}
