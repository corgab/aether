<?php

declare(strict_types=1);

namespace Aether\Results;

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use IteratorAggregate;
use JsonSerializable;
use OutOfBoundsException;
use Traversable;

/**
 * Immutable, ordered collection of CircuitResult objects produced by a batch execution.
 *
 * @implements Arrayable<int, array<string, mixed>>
 * @implements ArrayAccess<int, CircuitResult>
 * @implements IteratorAggregate<int, CircuitResult>
 */
class BatchResult implements \Stringable, Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    /**
     * @param  list<CircuitResult>  $results  One result per circuit, in submission order.
     */
    public function __construct(private readonly array $results) {}

    /**
     * Return every result, in the order the circuits were submitted.
     *
     * @return list<CircuitResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Return the result of the circuit at the given position.
     *
     * @throws OutOfBoundsException
     */
    public function get(int $index): CircuitResult
    {
        return $this->results[$index] ?? throw new OutOfBoundsException(
            "No result at index [{$index}]; the batch contains {$this->count()} result(s)."
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (CircuitResult $result): array => $result->toArray(), $this->results);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return Traversable<int, CircuitResult>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->results[$offset]);
    }

    public function offsetGet(mixed $offset): CircuitResult
    {
        return $this->get((int) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('BatchResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('BatchResult is immutable.');
    }
}
