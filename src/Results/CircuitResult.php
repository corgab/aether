<?php

declare(strict_types=1);

namespace Aether\Results;

use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonException;
use JsonSerializable;

/**
 * Immutable value object representing the measurement results of a quantum circuit run.
 *
 * @implements Arrayable<string, mixed>
 */
class CircuitResult implements \Stringable, Arrayable, Countable, Jsonable, JsonSerializable
{
    /**
     * @var array<string, int>
     */
    private readonly array $counts;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(array $counts)
    {
        // Normalize incoming keys to strings so counts() honestly returns
        // array<string, int> even when PHP has (or a caller has) turned
        // numeric-string outcomes such as "10" into integer array keys.
        $normalized = [];

        foreach ($counts as $bitstring => $count) {
            $normalized[(string) $bitstring] = $count;
        }

        $this->counts = $normalized;
    }

    /**
     * Return the raw measurement counts.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Return the probability of each outcome (count / total shots).
     *
     * @return array<string, float>
     */
    public function probabilities(): array
    {
        $total = array_sum($this->counts);

        if ($total === 0) {
            return array_map(static fn () => 0.0, $this->counts);
        }

        return array_map(
            static fn (int $count): float => $count / $total,
            $this->counts,
        );
    }

    /**
     * Return the probability of a single outcome (count / total shots).
     *
     * Returns 0.0 when the bitstring was never measured, or when there are
     * no shots at all — the same zero-total guard as probabilities().
     */
    public function probability(string $bitstring): float
    {
        $total = $this->shots();

        if ($total === 0) {
            return 0.0;
        }

        return ($this->counts[$bitstring] ?? 0) / $total;
    }

    /**
     * Return the bitstring with the highest measurement count.
     * On a tie, the first key (in insertion order) is returned.
     *
     * @throws \LogicException when counts are empty.
     */
    public function mostFrequent(): string
    {
        if ($this->counts === []) {
            throw new \LogicException('Cannot determine most frequent outcome from empty counts.');
        }

        $max = -1;
        $winner = (string) array_key_first($this->counts);

        foreach ($this->counts as $bitstring => $count) {
            if ($count > $max) {
                $max = $count;
                $winner = (string) $bitstring;
            }
        }

        return $winner;
    }

    /**
     * Return the bitstrings observed, sorted by measurement count in
     * descending order. Ties keep their relative insertion order (PHP's
     * sort functions have been stable since PHP 8.0).
     *
     * @return list<string>
     */
    public function outcomes(): array
    {
        $counts = $this->counts;

        arsort($counts);

        // array_keys() would otherwise hand back PHP's own int keys for
        // canonical-integer bitstrings (e.g. "11" decays to 11), the same
        // coercion the constructor and mostFrequent() guard against.
        return array_map(static fn (int|string $bitstring): string => (string) $bitstring, array_keys($counts));
    }

    /**
     * Return the total number of measurements (the sum of all counts).
     */
    public function shots(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Return the number of distinct measured outcomes, or — when a
     * bitstring is given — the measurement count for that specific
     * outcome (0 when it was never measured).
     *
     * The optional parameter keeps this signature-compatible with
     * Countable::count(): calling count($result) still returns the
     * original distinct-outcome count.
     */
    public function count(?string $bitstring = null): int
    {
        if ($bitstring === null) {
            return count($this->counts);
        }

        // Countable::count() is typed int<0, max> by PHPStan's builtin stub;
        // guard non-negative rather than trust plain array<string, int>.
        return max(0, $this->counts[$bitstring] ?? 0);
    }

    /**
     * Return a structured array representation of the result.
     *
     * `most_frequent` is null when there are no counts to derive it from,
     * instead of propagating mostFrequent()'s LogicException.
     *
     * @return array{counts: array<string, int>, probabilities: array<string, float>, most_frequent: string|null}
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts(),
            'probabilities' => $this->probabilities(),
            'most_frequent' => $this->counts === [] ? null : $this->mostFrequent(),
        ];
    }

    /**
     * Return the data used to serialize the result to JSON.
     *
     * @return array{counts: array<string, int>, probabilities: array<string, float>, most_frequent: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize the result to a JSON string.
     *
     * @param  int  $options
     *
     * @throws JsonException
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Return the JSON representation of the result.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
