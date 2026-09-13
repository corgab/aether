<?php

declare(strict_types=1);

namespace Aether\Testing\Concerns;

use InvalidArgumentException;

/**
 * Shared shape validation for stubbed measurement counts.
 *
 * Note: Stub validation deliberately throws native \InvalidArgumentException
 * for incorrect test-usage, mirroring Laravel's Http::fake().
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
