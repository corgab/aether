<?php

declare(strict_types=1);

namespace Aether\Results;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonException;

/**
 * Immutable value object representing the measurement results of a quantum circuit run.
 *
 * @implements Arrayable<string, mixed>
 */
class CircuitResult implements Arrayable, Jsonable, \Stringable
{
    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        private readonly array $counts,
    ) {}

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
     * Return the bitstring with the highest measurement count.
     * On a tie, the first key (in insertion order) is returned.
     */
    public function mostFrequent(): string
    {
        if ($this->counts === []) {
            throw new \LogicException('Cannot determine most frequent outcome from empty counts.');
        }

        $max = -1;
        $winner = (string) (array_key_first($this->counts) ?? '');

        foreach ($this->counts as $bitstring => $count) {
            if ($count > $max) {
                $max = $count;
                $winner = (string) $bitstring;
            }
        }

        return $winner;
    }

    /**
     * Return a structured array representation of the result.
     *
     * @return array{counts: array<string, int>, probabilities: array<string, float>, most_frequent: string}
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts(),
            'probabilities' => $this->probabilities(),
            'most_frequent' => $this->mostFrequent(),
        ];
    }

    /**
     * Serialize the result to a JSON string.
     *
     * @param  int  $options
     *
     * @throws \JsonException
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
