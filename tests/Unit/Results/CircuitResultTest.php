<?php

declare(strict_types=1);

use Aether\Results\CircuitResult;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

// -------------------------------------------------------------------------
// counts()
// -------------------------------------------------------------------------

it('counts returns raw data', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    expect($result->counts())->toBe(['00' => 503, '11' => 497]);
});

// -------------------------------------------------------------------------
// probabilities()
// -------------------------------------------------------------------------

it('probabilities calculates correctly', function (): void {
    $result = new CircuitResult(['00' => 600, '11' => 400]);

    $probabilities = $result->probabilities();

    expect($probabilities['00'])->toEqualWithDelta(0.6, 0.00001);
    expect($probabilities['11'])->toEqualWithDelta(0.4, 0.00001);
});

it('probabilities sum to one', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $sum = array_sum($result->probabilities());

    expect($sum)->toEqualWithDelta(1.0, 0.00001);
});

// -------------------------------------------------------------------------
// mostFrequent()
// -------------------------------------------------------------------------

it('most frequent returns highest count bitstring', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    expect($result->mostFrequent())->toBe('00');
});

it('most frequent with tie returns first', function (): void {
    $result = new CircuitResult(['00' => 500, '11' => 500]);

    expect($result->mostFrequent())->toBe('00');
});

// -------------------------------------------------------------------------
// toArray()
// -------------------------------------------------------------------------

it('to array has all keys', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $array = $result->toArray();

    expect($array)->toHaveKey('counts');
    expect($array)->toHaveKey('probabilities');
    expect($array)->toHaveKey('most_frequent');
});

it('to array contains correct values', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $array = $result->toArray();

    expect($array['counts'])->toBe(['00' => 503, '11' => 497]);
    expect($array['most_frequent'])->toBe('00');
    expect($array['probabilities']['00'])->toEqualWithDelta(0.503, 0.00001);
});

// -------------------------------------------------------------------------
// toJson()
// -------------------------------------------------------------------------

it('to json produces valid json', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $json = $result->toJson();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull();
    expect($decoded)->toHaveKey('counts');
    expect($decoded)->toHaveKey('probabilities');
    expect($decoded)->toHaveKey('most_frequent');
});

it('to json options are respected', function (): void {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $prettyJson = $result->toJson(JSON_PRETTY_PRINT);

    expect($prettyJson)->toContain("\n");
});

// -------------------------------------------------------------------------
// Contract implementations
// -------------------------------------------------------------------------

it('implements arrayable', function (): void {
    expect(new CircuitResult(['00' => 1]))->toBeInstanceOf(Arrayable::class);
});

it('implements jsonable', function (): void {
    expect(new CircuitResult(['00' => 1]))->toBeInstanceOf(Jsonable::class);
});

// -------------------------------------------------------------------------
// mostFrequent() — empty guard
// -------------------------------------------------------------------------

it('throws on mostFrequent with empty counts', function () {
    $result = new CircuitResult([]);
    $result->mostFrequent();
})->throws(LogicException::class);

// -------------------------------------------------------------------------
// Stringable
// -------------------------------------------------------------------------

it('implements Stringable', function () {
    $result = new CircuitResult(['00' => 500, '11' => 500]);
    expect($result)->toBeInstanceOf(Stringable::class);
});

it('converts to JSON string via __toString', function () {
    $result = new CircuitResult(['0' => 700, '1' => 300]);
    $string = (string) $result;
    $decoded = json_decode($string, true);
    expect($decoded)->toHaveKey('counts')
        ->and($decoded['counts'])->toBe(['0' => 700, '1' => 300]);
});

// -------------------------------------------------------------------------
// JsonSerializable
// -------------------------------------------------------------------------

it('implements JsonSerializable', function () {
    expect(new CircuitResult(['00' => 1]))->toBeInstanceOf(JsonSerializable::class);
});

it('jsonSerialize returns the same shape as toArray', function () {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    expect($result->jsonSerialize())->toBe($result->toArray());
});

it('json_encode produces the full structured payload, not an empty object', function () {
    $result = new CircuitResult(['00' => 503, '11' => 497]);

    $json = json_encode($result);
    $decoded = json_decode($json, true);

    expect($decoded)->toBe([
        'counts' => ['00' => 503, '11' => 497],
        'probabilities' => $result->probabilities(),
        'most_frequent' => '00',
    ]);
});

// -------------------------------------------------------------------------
// Countable
// -------------------------------------------------------------------------

it('implements Countable', function () {
    expect(new CircuitResult(['00' => 1]))->toBeInstanceOf(Countable::class);
});

it('count returns the number of distinct outcomes', function () {
    $result = new CircuitResult(['00' => 503, '01' => 10, '11' => 497]);

    expect(count($result))->toBe(3);
});

it('count is zero for empty counts', function () {
    $result = new CircuitResult([]);

    expect(count($result))->toBe(0);
});

// -------------------------------------------------------------------------
// toArray() — empty counts
// -------------------------------------------------------------------------

it('toArray returns null most_frequent for empty counts instead of throwing', function () {
    $result = new CircuitResult([]);

    $array = $result->toArray();

    expect($array['most_frequent'])->toBeNull()
        ->and($array['counts'])->toBe([])
        ->and($array['probabilities'])->toBe([]);
});

// -------------------------------------------------------------------------
// Numeric-string key normalization
// -------------------------------------------------------------------------

it('normalizes integer count keys to strings', function () {
    $result = new CircuitResult([10 => 500, 11 => 500]);

    expect($result->counts())->toBe(['10' => 500, '11' => 500]);
});
