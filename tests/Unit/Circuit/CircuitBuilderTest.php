<?php

declare(strict_types=1);

use Aether\Circuit\Angle;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Results\CircuitResult;

$device = null;
$builder = null;

beforeEach(function () use (&$device, &$builder): void {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device);
});

// -------------------------------------------------------------------------
// Fluent API
// -------------------------------------------------------------------------

it('qubits returns self', function () use (&$builder): void {
    expect($builder->qubits(2))->toBe($builder);
});

it('h returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->h(0))->toBe($builder);
});

it('x returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->x(1))->toBe($builder);
});

it('y returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->y(0))->toBe($builder);
});

it('z returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->z(1))->toBe($builder);
});

it('cnot returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->cnot(0, 1))->toBe($builder);
});

it('measure returns self', function () use (&$builder): void {
    expect($builder->measure())->toBe($builder);
});

it('shots returns self', function () use (&$builder): void {
    expect($builder->shots(500))->toBe($builder);
});

// -------------------------------------------------------------------------
// Default shots
// -------------------------------------------------------------------------

it('default shots is 1000', function () use (&$builder): void {
    expect($builder->toArray()['shots'])->toBe(1000);
});

// -------------------------------------------------------------------------
// qubitCount
// -------------------------------------------------------------------------

it('qubit count returns configured count', function () use (&$builder): void {
    $builder->qubits(3);

    expect($builder->qubitCount())->toBe(3);
});

it('qubit count is zero by default', function () use (&$builder): void {
    expect($builder->qubitCount())->toBe(0);
});

// -------------------------------------------------------------------------
// toArray serialisation
// -------------------------------------------------------------------------

it('to array contains correct structure', function () use (&$builder): void {
    $builder
        ->qubits(2)
        ->h(0)
        ->cnot(0, 1)
        ->measure()
        ->shots(512);

    $array = $builder->toArray();

    expect($array['qubits'])->toBe(2);
    expect($array['shots'])->toBe(512);
    expect($array['gates'])->toHaveCount(3);
    expect($array['gates'][0])->toBe(['type' => 'h', 'target' => 0]);
    expect($array['gates'][1])->toBe(['type' => 'cnot', 'control' => 0, 'target' => 1]);
    expect($array['gates'][2])->toBe(['type' => 'measure', 'targets' => null]);
});

it('to array gates are serialised via gate to array', function () use (&$builder): void {
    $builder->qubits(1)->x(0)->measure(0);

    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'x', 'target' => 0]);
    expect($gates[1])->toBe(['type' => 'measure', 'targets' => [0]]);
});

// -------------------------------------------------------------------------
// measure() overloads
// -------------------------------------------------------------------------

it('measure with null adds measure all gate', function () use (&$builder): void {
    $builder->qubits(1)->measure(null);

    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'measure', 'targets' => null]);
});

it('measure with int adds single qubit measure gate', function () use (&$builder): void {
    $builder->qubits(2)->measure(1);

    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'measure', 'targets' => [1]]);
});

it('measure with array adds multi qubit measure gate', function () use (&$builder): void {
    $builder->qubits(3)->measure([0, 2]);

    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'measure', 'targets' => [0, 2]]);
});

// -------------------------------------------------------------------------
// run() — delegates to device
// -------------------------------------------------------------------------

it('run delegates to device execute circuit', function () use (&$device, &$builder): void {
    $expected = new CircuitResult(['00' => 500, '11' => 500]);

    $device
        ->expects($this->once())
        ->method('executeCircuit')
        ->with($builder)
        ->willReturn($expected);

    $builder->qubits(2)->h(0)->cnot(0, 1)->measure();

    expect($builder->run())->toBe($expected);
});

// -------------------------------------------------------------------------
// run() — validation: no qubits
// -------------------------------------------------------------------------

it('run throws when no qubits defined', function () use (&$builder): void {
    expect(fn () => $builder->measure()->run())
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// run() — validation: no measurement
// -------------------------------------------------------------------------

it('run throws when no measurement', function () use (&$builder): void {
    expect(fn () => $builder->qubits(1)->h(0)->run())
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// Validation: gate target out of range
// -------------------------------------------------------------------------

it('h throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->h(5))
        ->toThrow(InvalidCircuitException::class);
});

it('x throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->x(2))
        ->toThrow(InvalidCircuitException::class);
});

it('y throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(1)->y(1))
        ->toThrow(InvalidCircuitException::class);
});

it('z throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(1)->z(3))
        ->toThrow(InvalidCircuitException::class);
});

it('cnot throws when control is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cnot(5, 1))
        ->toThrow(InvalidCircuitException::class);
});

it('cnot throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cnot(0, 5))
        ->toThrow(InvalidCircuitException::class);
});

it('gate on qubit zero with one qubit circuit does not throw', function () use (&$builder): void {
    // Qubit index 0 is valid on a 1-qubit circuit.
    $builder->qubits(1)->h(0);

    // No exception expected; reaching this assertion is the pass condition.
    expect(true)->toBeTrue();
});

// -------------------------------------------------------------------------
// Validation: negative qubit index
// -------------------------------------------------------------------------

it('h throws when target is negative', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->h(-1))
        ->toThrow(InvalidCircuitException::class);
});

it('cnot throws when control is negative', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cnot(-1, 0))
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// Validation: qubits() rejects invalid counts
// -------------------------------------------------------------------------

it('qubits throws when count is zero', function () use (&$builder): void {
    expect(fn () => $builder->qubits(0))
        ->toThrow(InvalidCircuitException::class);
});

it('qubits throws when count is negative', function () use (&$builder): void {
    expect(fn () => $builder->qubits(-1))
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// Validation: shots
// -------------------------------------------------------------------------

it('throws on zero shots', function () {
    $device = $this->createMock(QuantumDevice::class);
    (new CircuitBuilder($device))->qubits(1)->shots(0);
})->throws(InvalidCircuitException::class);

it('throws on negative shots', function () {
    $device = $this->createMock(QuantumDevice::class);
    (new CircuitBuilder($device))->qubits(1)->shots(-5);
})->throws(InvalidCircuitException::class);

it('exposes shot count via shotCount()', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = (new CircuitBuilder($device))->qubits(1)->shots(2048);
    expect($builder->shotCount())->toBe(2048);
});

it('returns default shot count of 1000', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = (new CircuitBuilder($device))->qubits(1);
    expect($builder->shotCount())->toBe(1000);
});

// -------------------------------------------------------------------------
// Validation: measure target indices
// -------------------------------------------------------------------------

it('validates measure target indices', function () {
    $device = $this->createMock(QuantumDevice::class);
    (new CircuitBuilder($device))->qubits(2)->measure(5);
})->throws(InvalidCircuitException::class);

it('validates measure target array indices', function () {
    $device = $this->createMock(QuantumDevice::class);
    (new CircuitBuilder($device))->qubits(2)->measure([0, 9]);
})->throws(InvalidCircuitException::class);

it('allows measure with null targets', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = (new CircuitBuilder($device))->qubits(2)->measure();
    expect($builder->qubitCount())->toBe(2);
});

// -------------------------------------------------------------------------
// Fluent API: new gates return self
// -------------------------------------------------------------------------

it('s returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->s(0))->toBe($builder);
});

it('t returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->t(1))->toBe($builder);
});

it('rx returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->rx(0, M_PI / 2))->toBe($builder);
});

it('ry returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->ry(0, M_PI))->toBe($builder);
});

it('rz returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->rz(0, 0.5))->toBe($builder);
});

it('swap returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->swap(0, 1))->toBe($builder);
});

it('cz returns self', function () use (&$builder): void {
    $builder->qubits(2);

    expect($builder->cz(0, 1))->toBe($builder);
});

it('ccnot returns self', function () use (&$builder): void {
    $builder->qubits(3);

    expect($builder->ccnot(0, 1, 2))->toBe($builder);
});

it('barrier returns self', function () use (&$builder): void {
    $builder->qubits(1);

    expect($builder->barrier())->toBe($builder);
});

// -------------------------------------------------------------------------
// toArray: new gates serialize correctly
// -------------------------------------------------------------------------

it('s gate serializes in toArray', function () use (&$builder): void {
    $builder->qubits(1)->s(0)->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 's', 'target' => 0]);
});

it('rx gate serializes angle in toArray', function () use (&$builder): void {
    $builder->qubits(1)->rx(0, M_PI / 2)->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0]['type'])->toBe('rx');
    expect($gates[0]['target'])->toBe(0);
    expect($gates[0]['angle'])->toEqualWithDelta(M_PI / 2, 1e-10);
});

it('rx accepts Angle value object', function () use (&$builder): void {
    $builder->qubits(1)->rx(0, Angle::deg(90.0))->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0]['angle'])->toEqualWithDelta(M_PI / 2, 1e-10);
});

it('swap gate serializes in toArray', function () use (&$builder): void {
    $builder->qubits(2)->swap(0, 1)->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'swap', 'target0' => 0, 'target1' => 1]);
});

it('ccnot gate serializes in toArray', function () use (&$builder): void {
    $builder->qubits(3)->ccnot(0, 1, 2)->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'ccnot', 'control0' => 0, 'control1' => 1, 'target' => 2]);
});

it('cz gate serializes in toArray', function () use (&$builder): void {
    $builder->qubits(2)->cz(0, 1)->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[0])->toBe(['type' => 'cz', 'control' => 0, 'target' => 1]);
});

it('barrier serializes in toArray', function () use (&$builder): void {
    $builder->qubits(1)->h(0)->barrier()->measure();
    $gates = $builder->toArray()['gates'];

    expect($gates[1])->toBe(['type' => 'barrier']);
});

// -------------------------------------------------------------------------
// Validation: new gates out of range
// -------------------------------------------------------------------------

it('s throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->s(5))
        ->toThrow(InvalidCircuitException::class);
});

it('t throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->t(5))
        ->toThrow(InvalidCircuitException::class);
});

it('rx throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->rx(5, M_PI))
        ->toThrow(InvalidCircuitException::class);
});

it('swap throws when target0 is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->swap(5, 1))
        ->toThrow(InvalidCircuitException::class);
});

it('swap throws when target1 is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->swap(0, 5))
        ->toThrow(InvalidCircuitException::class);
});

it('ccnot throws when control0 is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(3)->ccnot(5, 1, 2))
        ->toThrow(InvalidCircuitException::class);
});

it('ccnot throws when control1 is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(3)->ccnot(0, 5, 2))
        ->toThrow(InvalidCircuitException::class);
});

it('ccnot throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(3)->ccnot(0, 1, 5))
        ->toThrow(InvalidCircuitException::class);
});

it('cz throws when control is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cz(5, 1))
        ->toThrow(InvalidCircuitException::class);
});

it('cz throws when target is out of range', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cz(0, 5))
        ->toThrow(InvalidCircuitException::class);
});
