<?php

declare(strict_types=1);

namespace Aether\Results;

use Aether\Contracts\EstimatesCost;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object representing an estimated AWS Braket cost.
 *
 * Produced by drivers implementing {@see EstimatesCost}
 * from their configured `pricing` rates — no network call is made.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CostEstimate implements Arrayable, Jsonable, JsonSerializable, Stringable
{
    /**
     * @param  float  $amount  The total estimated cost (per_task + per_shot components).
     * @param  string  $currency  The ISO currency code the rates are denominated in (e.g. "USD").
     * @param  int  $shots  The total number of shots the estimate covers (summed across tasks).
     * @param  array{per_task: float, per_shot: float}  $breakdown  The dollar contribution of each pricing component.
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public int $shots,
        public array $breakdown,
    ) {}

    /**
     * Return a structured array representation of the estimate.
     *
     * @return array{amount: float, currency: string, shots: int, breakdown: array{per_task: float, per_shot: float}}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'shots' => $this->shots,
            'breakdown' => $this->breakdown,
        ];
    }

    /**
     * Return the data used to serialize the estimate to JSON.
     *
     * @return array{amount: float, currency: string, shots: int, breakdown: array{per_task: float, per_shot: float}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize the estimate to a JSON string.
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
     * Return a human-readable "amount currency" representation, e.g. "0.65 USD".
     */
    public function __toString(): string
    {
        return number_format($this->amount, 2).' '.$this->currency;
    }
}
