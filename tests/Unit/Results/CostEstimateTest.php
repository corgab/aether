<?php

declare(strict_types=1);

use Aether\Results\CostEstimate;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

// -------------------------------------------------------------------------
// Construction / property access
// -------------------------------------------------------------------------

it('exposes the given amount, currency, shots and breakdown', function () {
    $estimate = new CostEstimate(
        amount: 0.65,
        currency: 'USD',
        shots: 1000,
        breakdown: ['per_task' => 0.30, 'per_shot' => 0.35],
    );

    expect($estimate->amount)->toBe(0.65);
    expect($estimate->currency)->toBe('USD');
    expect($estimate->shots)->toBe(1000);
    expect($estimate->breakdown)->toBe(['per_task' => 0.30, 'per_shot' => 0.35]);
});

it('is a readonly value object', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect(fn () => $estimate->amount = 1.0)->toThrow(Error::class);
});

// -------------------------------------------------------------------------
// Serialization
// -------------------------------------------------------------------------

it('implements Arrayable and Jsonable', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect($estimate)->toBeInstanceOf(Arrayable::class);
    expect($estimate)->toBeInstanceOf(Jsonable::class);
    expect($estimate)->toBeInstanceOf(JsonSerializable::class);
    expect($estimate)->toBeInstanceOf(Stringable::class);
});

it('converts to a structured array via toArray', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect($estimate->toArray())->toBe([
        'amount' => 0.65,
        'currency' => 'USD',
        'shots' => 1000,
        'breakdown' => ['per_task' => 0.30, 'per_shot' => 0.35],
    ]);
});

it('uses toArray as its jsonSerialize representation', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect($estimate->jsonSerialize())->toBe($estimate->toArray());
});

it('serializes to a JSON string via toJson', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect($estimate->toJson())->toBe(json_encode($estimate->toArray()));
});

// -------------------------------------------------------------------------
// __toString
// -------------------------------------------------------------------------

it('formats __toString as "amount currency" with two decimals', function () {
    $estimate = new CostEstimate(0.65, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.35]);

    expect((string) $estimate)->toBe('0.65 USD');
});

it('pads __toString to at least two decimals', function () {
    $estimate = new CostEstimate(0.6, 'USD', 1000, ['per_task' => 0.30, 'per_shot' => 0.30]);

    expect((string) $estimate)->toBe('0.60 USD');
});

it('keeps sub-cent precision in __toString instead of collapsing to 0.00', function () {
    $estimate = new CostEstimate(0.0035, 'USD', 10, ['per_task' => 0.0, 'per_shot' => 0.0035]);

    expect((string) $estimate)->toBe('0.0035 USD');
});

it('formats a zero amount with two decimals', function () {
    expect(CostEstimate::formatAmount(0.0, 'USD'))->toBe('0.00 USD')
        ->and(CostEstimate::formatAmount(12.5, 'EUR'))->toBe('12.50 EUR');
});
