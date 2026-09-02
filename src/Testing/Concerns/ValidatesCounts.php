<?php

declare(strict_types=1);

namespace Aether\Testing\Concerns;

use InvalidArgumentException;

/**
 * Shared shape validation for stubbed measurement counts.
 *
 * Used by both QuantumFake and ResultSequence so every entry point that
 * accepts a raw counts array (respondWith(), ResultSequence::push(),
 * ResultSequence::whenEmpty(), QuantumFake::result()) rejects malformed
 * stubs at the point they are set, rather than failing later with a
 * confusing error deep inside CircuitResult or a consumer under test.
 *
 * An empty array is deliberately accepted: CircuitResult([]) is a legal
 * value (zero shots, mostFrequent() unavailable) and tests need to be able
 * to stub that branch.
 */
trait ValidatesCounts
{
    /**
     * @param  array<array-key, mixed>  $counts
     */
    private static function assertValidCounts(array $counts): void
    {
        foreach ($counts as $bitstring => $count) {
            if (! preg_match('/^[01]+$/', (string) $bitstring)) {
                throw new InvalidArgumentException(
                    "Stubbed counts key [{$bitstring}] is not a valid bitstring (expected a string of 0s and 1s)."
                );
            }

            if (! is_int($count) || $count < 0) {
                $type = get_debug_type($count);

                throw new InvalidArgumentException(
                    "Stubbed count for outcome [{$bitstring}] must be a non-negative integer, {$type} given."
                );
            }
        }
    }
}
