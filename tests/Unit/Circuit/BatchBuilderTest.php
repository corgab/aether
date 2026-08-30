<?php

declare(strict_types=1);

use Aether\Circuit\BatchBuilder;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;
use Aether\Testing\QuantumFake;

it('runs every circuit through the device and returns one result per circuit', function () {
    $device = new QuantumFake;
    $first = (new CircuitBuilder($device))->qubits(1)->shots(100)->measure();
    $second = (new CircuitBuilder($device))->qubits(2)->shots(10)->h(0)->measure();

    $result = (new BatchBuilder($device, [$first, $second], 'local'))->run();

    expect($result)->toBeInstanceOf(BatchResult::class)
        ->and($result)->toHaveCount(2)
        ->and($result->get(0)->counts())->toBe(['0' => 50, '1' => 50])
        ->and($result->get(1)->counts())->toBe(['00' => 5, '11' => 5]);

    $device->assertBatchRan(fn (array $circuits) => $circuits === [$first, $second]);
    $device->assertCircuitRanTimes(2);
});

it('validates every circuit before executing anything', function () {
    $device = new QuantumFake;
    $complete = (new CircuitBuilder($device))->qubits(1)->measure();
    $unmeasured = (new CircuitBuilder($device))->qubits(1)->h(0);

    expect(fn () => (new BatchBuilder($device, [$complete, $unmeasured], 'local'))->run())
        ->toThrow(InvalidCircuitException::class, 'measurement');

    $device->assertBatchNotRan();
    $device->assertCircuitNotRan();
});

it('rejects a circuit without qubits', function () {
    $device = new QuantumFake;

    (new BatchBuilder($device, [new CircuitBuilder($device)], 'local'))->run();
})->throws(InvalidCircuitException::class, 'qubit');

it('throws when the device does not implement BatchableDevice', function () {
    $device = $this->createMock(QuantumDevice::class);
    $circuit = (new CircuitBuilder($device))->qubits(1)->measure();

    (new BatchBuilder($device, [$circuit], 'custom'))->run();
})->throws(QuantumExecutionException::class, 'Driver [custom] does not support batch execution');

it('rejects a circuit pinned to a different driver than the batch', function () {
    $device = new QuantumFake;
    $pinned = (new CircuitBuilder($device, 'aws'))->qubits(1)->measure();

    new BatchBuilder($device, [$pinned], 'local');
})->throws(InvalidCircuitException::class, 'targeting driver [local], but circuit is pinned to driver [aws]');

it('accepts circuits pinned to the same driver or to none', function () {
    $device = new QuantumFake;
    $pinned = (new CircuitBuilder($device, 'local'))->qubits(1)->measure();
    $unpinned = (new CircuitBuilder($device))->qubits(1)->measure();

    $result = (new BatchBuilder($device, [$pinned, $unpinned], 'local'))->run();

    expect($result)->toHaveCount(2);
});
