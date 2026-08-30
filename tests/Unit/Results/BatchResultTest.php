<?php

declare(strict_types=1);

use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;

it('implements arrayable and jsonable', function () {
    $result1 = new CircuitResult(['00' => 500, '11' => 500]);
    $result2 = new CircuitResult(['01' => 1000]);

    $batch = new BatchResult([$result1, $result2]);

    expect($batch->toArray())->toBe([
        $result1->toArray(),
        $result2->toArray(),
    ]);

    expect($batch->toJson())->toBe(json_encode([
        $result1->toArray(),
        $result2->toArray(),
    ]));

    expect((string) $batch)->toBe($batch->toJson());
});

it('implements array access and iterable', function () {
    $result1 = new CircuitResult(['00' => 500]);
    $result2 = new CircuitResult(['11' => 500]);

    $batch = new BatchResult([$result1, $result2]);

    expect(count($batch))->toBe(2);
    expect($batch[0])->toBe($result1);
    expect($batch[1])->toBe($result2);
    expect(isset($batch[0]))->toBeTrue();
    expect(isset($batch[2]))->toBeFalse();

    $iterated = [];
    foreach ($batch as $key => $val) {
        $iterated[$key] = $val;
    }
    expect($iterated)->toBe([$result1, $result2]);
});

it('throws on array mutation', function () {
    $batch = new BatchResult([]);
    expect(fn () => $batch[0] = new CircuitResult([]))->toThrow(BadMethodCallException::class);
});

it('exposes results and get', function () {
    $result1 = new CircuitResult(['00' => 500]);
    $batch = new BatchResult([$result1]);

    expect($batch->results())->toBe([$result1]);
    expect($batch->get(0))->toBe($result1);
});

it('throws OutOfBoundsException for get', function () {
    $batch = new BatchResult([]);
    expect(fn () => $batch->get(1))->toThrow(OutOfBoundsException::class);
});

it('throws OutOfBoundsException for offsetGet', function () {
    $batch = new BatchResult([]);
    expect(fn () => $batch[1])->toThrow(OutOfBoundsException::class);
});
