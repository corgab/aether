<?php

declare(strict_types=1);

use Aether\Circuit\Angle;
use Aether\Circuit\CircuitBuilder;
use Aether\Circuit\Gate;
use Aether\Circuit\GateType;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;
use Aether\Tests\Unit\Circuit\FakeCostEstimatingDevice;

$device = null;
$builder = null;

beforeEach(function () use (&$device, &$builder): void {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device);
});

// -------------------------------------------------------------------------
// Hand-written wire-contract dataset — one entry per GateType, used both to
// assert exact serialisation and (below) to assert dataset completeness.
// Stays hand-written on purpose: never generate expected wire arrays from
// the GateShape/GateType metadata, or the test stops checking anything.
// -------------------------------------------------------------------------

$gateWireContract = [
    'h' => [fn ($b) => $b->h(0), ['type' => 'h', 'target' => 0]],
    'x' => [fn ($b) => $b->x(0), ['type' => 'x', 'target' => 0]],
    'y' => [fn ($b) => $b->y(0), ['type' => 'y', 'target' => 0]],
    'z' => [fn ($b) => $b->z(0), ['type' => 'z', 'target' => 0]],
    'i' => [fn ($b) => $b->i(0), ['type' => 'i', 'target' => 0]],
    's' => [fn ($b) => $b->s(0), ['type' => 's', 'target' => 0]],
    'si' => [fn ($b) => $b->si(0), ['type' => 'si', 'target' => 0]],
    't' => [fn ($b) => $b->t(0), ['type' => 't', 'target' => 0]],
    'ti' => [fn ($b) => $b->ti(0), ['type' => 'ti', 'target' => 0]],
    'rx' => [fn ($b) => $b->rx(0, 0.5), ['type' => 'rx', 'target' => 0, 'angle' => 0.5]],
    'ry' => [fn ($b) => $b->ry(0, 0.5), ['type' => 'ry', 'target' => 0, 'angle' => 0.5]],
    'rz' => [fn ($b) => $b->rz(0, 0.5), ['type' => 'rz', 'target' => 0, 'angle' => 0.5]],
    'phaseshift' => [fn ($b) => $b->phaseshift(0, 0.5), ['type' => 'phaseshift', 'target' => 0, 'angle' => 0.5]],
    'cnot' => [fn ($b) => $b->cnot(0, 1), ['type' => 'cnot', 'control' => 0, 'target' => 1]],
    'cz' => [fn ($b) => $b->cz(0, 1), ['type' => 'cz', 'control' => 0, 'target' => 1]],
    'cy' => [fn ($b) => $b->cy(0, 1), ['type' => 'cy', 'control' => 0, 'target' => 1]],
    'crx' => [fn ($b) => $b->crx(0, 1, 0.5), ['type' => 'crx', 'control' => 0, 'target' => 1, 'angle' => 0.5]],
    'cry' => [fn ($b) => $b->cry(0, 1, 0.5), ['type' => 'cry', 'control' => 0, 'target' => 1, 'angle' => 0.5]],
    'crz' => [fn ($b) => $b->crz(0, 1, 0.5), ['type' => 'crz', 'control' => 0, 'target' => 1, 'angle' => 0.5]],
    'cphaseshift' => [
        fn ($b) => $b->cphaseshift(0, 1, 0.5),
        ['type' => 'cphaseshift', 'control' => 0, 'target' => 1, 'angle' => 0.5],
    ],
    'swap' => [fn ($b) => $b->swap(0, 1), ['type' => 'swap', 'target0' => 0, 'target1' => 1]],
    'iswap' => [fn ($b) => $b->iswap(0, 1), ['type' => 'iswap', 'target0' => 0, 'target1' => 1]],
    'xx' => [fn ($b) => $b->xx(0, 1, 0.5), ['type' => 'xx', 'target0' => 0, 'target1' => 1, 'angle' => 0.5]],
    'yy' => [fn ($b) => $b->yy(0, 1, 0.5), ['type' => 'yy', 'target0' => 0, 'target1' => 1, 'angle' => 0.5]],
    'zz' => [fn ($b) => $b->zz(0, 1, 0.5), ['type' => 'zz', 'target0' => 0, 'target1' => 1, 'angle' => 0.5]],
    'cswap' => [fn ($b) => $b->cswap(0, 1, 2), ['type' => 'cswap', 'control' => 0, 'target0' => 1, 'target1' => 2]],
    'ccnot' => [
        fn ($b) => $b->ccnot(0, 1, 2),
        ['type' => 'ccnot', 'control0' => 0, 'control1' => 1, 'target' => 2],
    ],
    'u' => [
        fn ($b) => $b->u(0, 0.1, 0.2, 0.3),
        ['type' => 'u', 'target' => 0, 'theta' => 0.1, 'phi' => 0.2, 'lambda' => 0.3],
    ],
    'measure' => [fn ($b) => $b->measure([0, 2]), ['type' => 'measure', 'targets' => [0, 2]]],
];

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
// estimateCost() — delegates to device
// -------------------------------------------------------------------------

it('estimateCost delegates to a device implementing EstimatesCost', function () {
    $device = new FakeCostEstimatingDevice;
    $builder = new CircuitBuilder($device);
    $builder->qubits(2)->h(0)->cnot(0, 1)->measure()->shots(1000);

    $estimate = $builder->estimateCost();

    expect($estimate)->toBe($device->estimateToReturn);
    expect($device->shotsPassedToEstimateCost)->toBe(1000);
    expect($device->tasksPassedToEstimateCost)->toBe(1);
});

it('estimateCost throws when the device does not implement EstimatesCost', function () use (&$device, &$builder): void {
    $builder->qubits(1)->h(0)->measure();

    expect(fn () => $builder->estimateCost())
        ->toThrow(QuantumExecutionException::class);
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

it('measure with an empty array throws', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->measure([]))
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// Validation: qubits() shrink revalidates existing gates
// -------------------------------------------------------------------------

it('qubits throws when shrinking below an existing gate target', function () use (&$builder): void {
    $builder->qubits(3)->h(2);

    expect(fn () => $builder->qubits(1))
        ->toThrow(InvalidCircuitException::class);
});

it('qubits throws when shrinking below an existing two qubit gate target', function () use (&$builder): void {
    $builder->qubits(3)->cnot(0, 2);

    expect(fn () => $builder->qubits(2))
        ->toThrow(InvalidCircuitException::class);
});

it('qubits allows shrinking when no gate is out of range', function () use (&$builder): void {
    $builder->qubits(3)->h(0)->x(1);

    expect($builder->qubits(2))->toBe($builder);
    expect($builder->qubitCount())->toBe(2);
});

it('qubits does not mutate count when shrink is rejected', function () use (&$builder): void {
    $builder->qubits(3)->h(2);

    try {
        $builder->qubits(1);
    } catch (InvalidCircuitException) {
        // ignored — asserting state below
    }

    expect($builder->qubitCount())->toBe(3);
});

it('qubits allows shrinking when only a measure all gate exists', function () use (&$builder): void {
    $builder->qubits(3)->measure();

    expect($builder->qubits(1))->toBe($builder);
    expect($builder->qubitCount())->toBe(1);
});

it('qubits throws when shrinking below an explicit measure target', function () use (&$builder): void {
    $builder->qubits(3)->measure([0, 2]);

    expect(fn () => $builder->qubits(2))
        ->toThrow(InvalidCircuitException::class);
});

it('qubits allows shrinking when explicit measure targets stay in range', function () use (&$builder): void {
    $builder->qubits(3)->measure([0, 1]);

    expect($builder->qubits(2))->toBe($builder);
    expect($builder->qubitCount())->toBe(2);
});

// -------------------------------------------------------------------------
// Conditionable / Tappable traits
// -------------------------------------------------------------------------

it('when applies the callback when the condition is truthy', function () use (&$builder): void {
    $builder->qubits(2)->when(true, fn (CircuitBuilder $c) => $c->h(0));

    expect($builder->toArray()['gates'])->toHaveCount(1);
    expect($builder->toArray()['gates'][0])->toBe(['type' => 'h', 'target' => 0]);
});

it('when skips the callback when the condition is falsy', function () use (&$builder): void {
    $builder->qubits(2)->when(false, fn (CircuitBuilder $c) => $c->h(0));

    expect($builder->toArray()['gates'])->toHaveCount(0);
});

it('unless applies the callback when the condition is falsy', function () use (&$builder): void {
    $builder->qubits(2)->unless(false, fn (CircuitBuilder $c) => $c->h(0));

    expect($builder->toArray()['gates'])->toHaveCount(1);
});

it('unless skips the callback when the condition is truthy', function () use (&$builder): void {
    $builder->qubits(2)->unless(true, fn (CircuitBuilder $c) => $c->h(0));

    expect($builder->toArray()['gates'])->toHaveCount(0);
});

it('tap runs a callback and returns the same instance', function () use (&$builder): void {
    $tapped = null;

    $result = $builder->qubits(2)->tap(function (CircuitBuilder $c) use (&$tapped): void {
        $tapped = $c;
    });

    expect($result)->toBe($builder);
    expect($tapped)->toBe($builder);
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

// -------------------------------------------------------------------------
// Validation: every gate type throws when any qubit-index parameter is
// out of range (metadata-generated from GateType/GateShape)
// -------------------------------------------------------------------------

it('throws when a gate targets an out-of-range qubit', function (string $method, array $args) use (&$builder): void {
    $builder->qubits(3);

    expect(fn () => $builder->{$method}(...$args))
        ->toThrow(InvalidCircuitException::class);
})->with(function (): array {
    $dataset = [];

    foreach (GateType::cases() as $type) {
        if ($type === GateType::Measure) {
            continue;
        }

        $shape = $type->shape();
        $qubitKeys = $shape->qubitKeys();
        $angleValues = [0.5, 0.5, 0.5];

        foreach ($qubitKeys as $outOfRangePosition => $outOfRangeKey) {
            $args = [];

            foreach ($qubitKeys as $position => $key) {
                $args[] = $position === $outOfRangePosition ? 99 : $position;
            }

            foreach ($shape->angleKeys() as $angleIndex => $angleKey) {
                $args[] = $angleValues[$angleIndex];
            }

            $dataset["{$type->value} {$outOfRangeKey}"] = [$type->value, $args];
        }
    }

    return $dataset;
});

// -------------------------------------------------------------------------
// driverName() accessor + BC of single-arg construction
// -------------------------------------------------------------------------

it('driverName is null when constructed with a single argument', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device);

    expect($builder->driverName())->toBeNull();
});

it('driverName returns the name passed to the constructor', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device, 'aws');

    expect($builder->driverName())->toBe('aws');
});

it('driverName is null when explicitly passed null', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device, null);

    expect($builder->driverName())->toBeNull();
});

// -------------------------------------------------------------------------
// fromArray() — round trips every gate type
// -------------------------------------------------------------------------

it('fromArray round trips a circuit with every gate type', function () {
    $device = $this->createMock(QuantumDevice::class);

    $gates = [];
    $angleValues = [0.1, 0.2, 0.3];

    foreach (GateType::cases() as $type) {
        if ($type === GateType::Measure) {
            continue;
        }

        $shape = $type->shape();
        $definition = ['type' => $type->value];

        foreach ($shape->qubitKeys() as $index => $key) {
            $definition[$key] = $index;
        }

        foreach ($shape->angleKeys() as $index => $key) {
            $definition[$key] = $angleValues[$index];
        }

        $gates[] = $definition;
    }

    $gates[] = ['type' => 'measure', 'targets' => [0, 2]];

    $definition = [
        'qubits' => 3,
        'gates' => $gates,
        'shots' => 2048,
    ];

    $rebuilt = CircuitBuilder::fromArray($definition, $device);

    expect($rebuilt->toArray())->toBe($definition);
});

it('fromArray round trips a measure-all circuit', function () {
    $device = $this->createMock(QuantumDevice::class);
    $original = (new CircuitBuilder($device))->qubits(2)->h(0)->cnot(0, 1)->measure();

    $rebuilt = CircuitBuilder::fromArray($original->toArray(), $device);

    expect($rebuilt->toArray())->toBe($original->toArray());
});

it('fromArray round trips a single target measure circuit', function () {
    $device = $this->createMock(QuantumDevice::class);
    $original = (new CircuitBuilder($device))->qubits(2)->x(0)->measure(0);

    $rebuilt = CircuitBuilder::fromArray($original->toArray(), $device);

    expect($rebuilt->toArray())->toBe($original->toArray());
});

it('fromArray round trips a multi target measure circuit', function () {
    $device = $this->createMock(QuantumDevice::class);
    $original = (new CircuitBuilder($device))->qubits(3)->measure([0, 2]);

    $rebuilt = CircuitBuilder::fromArray($original->toArray(), $device);

    expect($rebuilt->toArray())->toBe($original->toArray());
});

it('fromArray accepts an explicit driver name', function () {
    $device = $this->createMock(QuantumDevice::class);
    $original = (new CircuitBuilder($device))->qubits(1)->measure();

    $rebuilt = CircuitBuilder::fromArray($original->toArray(), $device, 'aws');

    expect($rebuilt->driverName())->toBe('aws');
});

it('fromArray defaults driver name to null', function () {
    $device = $this->createMock(QuantumDevice::class);
    $original = (new CircuitBuilder($device))->qubits(1)->measure();

    $rebuilt = CircuitBuilder::fromArray($original->toArray(), $device);

    expect($rebuilt->driverName())->toBeNull();
});

it('fromArray throws InvalidCircuitException for an unknown gate type', function () {
    $device = $this->createMock(QuantumDevice::class);

    $definition = [
        'qubits' => 1,
        'gates' => [
            ['type' => 'not-a-real-gate', 'target' => 0],
        ],
        'shots' => 1000,
    ];

    expect(fn () => CircuitBuilder::fromArray($definition, $device))
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// dispatch() — validation mirrors run()
// -------------------------------------------------------------------------

it('dispatch throws when no qubits defined', function () use (&$builder): void {
    expect(fn () => $builder->measure()->dispatch())
        ->toThrow(InvalidCircuitException::class);
});

it('dispatch throws when no measurement', function () use (&$builder): void {
    expect(fn () => $builder->qubits(1)->h(0)->dispatch())
        ->toThrow(InvalidCircuitException::class);
});

it('queue throws when no qubits defined', function () use (&$builder): void {
    expect(fn () => $builder->measure()->queue())
        ->toThrow(InvalidCircuitException::class);
});

it('queue throws when no measurement', function () use (&$builder): void {
    expect(fn () => $builder->qubits(1)->h(0)->queue())
        ->toThrow(InvalidCircuitException::class);
});

it('fluent api works for new gates', function () use (&$builder): void {
    $builder->qubits(3)
        ->crx(0, 1, 0.5)
        ->cry(0, 1, 0.5)
        ->crz(0, 1, 0.5)
        ->cphaseshift(0, 1, 0.5)
        ->phaseshift(0, 0.5)
        ->u(0, 0.1, 0.2, 0.3)
        ->cswap(0, 1, 2)
        ->iswap(0, 1)
        ->xx(0, 1, 0.5)
        ->yy(0, 1, 0.5)
        ->zz(0, 1, 0.5);

    expect($builder->toArray()['gates'])->toHaveCount(11);
});

it('throws when target out of range for new gates', function () use (&$builder): void {
    expect(fn () => $builder->qubits(2)->cswap(0, 1, 2))
        ->toThrow(InvalidCircuitException::class);
});

// -------------------------------------------------------------------------
// Wire contract: every gate type serialises exactly as expected
// -------------------------------------------------------------------------

it('serialises every gate type exactly to the wire contract', function (Closure $build, array $expected): void {
    $device = $this->createMock(QuantumDevice::class);
    $builder = (new CircuitBuilder($device))->qubits(4);
    $build($builder);

    expect($builder->toArray()['gates'][0])->toBe($expected);
})->with($gateWireContract);

it('wire contract dataset covers every gate type exactly once', function () use ($gateWireContract): void {
    $datasetTypes = array_keys($gateWireContract);
    $enumTypes = array_column(GateType::cases(), 'value');

    sort($datasetTypes);
    sort($enumTypes);

    expect($datasetTypes)->toBe($enumTypes);
});

// -------------------------------------------------------------------------
// append() - composition
// -------------------------------------------------------------------------

it('append copies gates from another circuit builder', function () {
    $device = $this->createMock(QuantumDevice::class);
    $sub = (new CircuitBuilder($device))->qubits(2)->h(0)->cnot(0, 1)->measure();

    $builder = (new CircuitBuilder($device))->qubits(2)->x(0);
    $builder->append($sub);

    $gates = $builder->toArray()['gates'];
    expect($gates)->toHaveCount(3); // x, h, cnot. measure is dropped.
    expect($gates[0]['type'])->toBe('x');
    expect($gates[1]['type'])->toBe('h');
    expect($gates[2]['type'])->toBe('cnot');
});

it('append executes a closure fragment on a new builder', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = (new CircuitBuilder($device))->qubits(2)->x(0);

    $builder->append(fn (CircuitBuilder $c) => $c->h(0)->cnot(0, 1));

    $gates = $builder->toArray()['gates'];
    expect($gates)->toHaveCount(3);
    expect($gates[0]['type'])->toBe('x');
    expect($gates[1]['type'])->toBe('h');
    expect($gates[2]['type'])->toBe('cnot');
});

it('append throws if fragment requires more qubits than available', function () {
    $device = $this->createMock(QuantumDevice::class);
    $sub = (new CircuitBuilder($device))->qubits(3)->ccnot(0, 1, 2);

    $builder = (new CircuitBuilder($device))->qubits(2);
    expect(fn () => $builder->append($sub))
        ->toThrow(InvalidCircuitException::class);
});

it('append throws when the circuit has no qubits yet', function () {
    $device = $this->createMock(QuantumDevice::class);
    $builder = new CircuitBuilder($device);

    expect(fn () => $builder->append(fn (CircuitBuilder $c) => $c->h(0)))
        ->toThrow(InvalidCircuitException::class, 'The circuit must have at least one qubit');
});

// -------------------------------------------------------------------------
// Bidirectional completeness: every GateType case has a matching fluent
// method on CircuitBuilder and factory on Gate, and vice versa.
// -------------------------------------------------------------------------

it('every gate type has a matching CircuitBuilder and Gate method', function (GateType $type): void {
    expect(method_exists(CircuitBuilder::class, $type->value))->toBeTrue();
    expect(method_exists(Gate::class, $type->value))->toBeTrue();
})->with(GateType::cases());

// -------------------------------------------------------------------------
// gateCount()
// -------------------------------------------------------------------------

it('gateCount is zero for an empty circuit', function () use (&$builder): void {
    expect($builder->gateCount())->toBe(0);
});

it('gateCount counts gates and excludes measurement', function () use (&$builder): void {
    $builder->qubits(2)->h(0)->cnot(0, 1)->measure();

    expect($builder->gateCount())->toBe(2);
});

// -------------------------------------------------------------------------
// depth()
// -------------------------------------------------------------------------

it('depth is zero for an empty circuit', function () use (&$builder): void {
    expect($builder->depth())->toBe(0);
});

it('depth is one for a single gate', function () use (&$builder): void {
    $builder->qubits(1)->h(0);

    expect($builder->depth())->toBe(1);
});

it('depth is one for parallel gates on disjoint qubits', function () use (&$builder): void {
    $builder->qubits(2)->h(0)->x(1);

    expect($builder->depth())->toBe(1);
});

it('depth grows with a serial chain on the same qubit', function () use (&$builder): void {
    $builder->qubits(1)->h(0)->x(0)->z(0);

    expect($builder->depth())->toBe(3);
});

it('depth accounts for entangling overlap between qubits', function () use (&$builder): void {
    // h(0), h(1) run in parallel (layer 1); cnot(0, 1) must wait for both
    // (layer 2); x(1) then waits for cnot's qubit 1 (layer 3).
    $builder->qubits(2)->h(0)->h(1)->cnot(0, 1)->x(1);

    expect($builder->depth())->toBe(3);
});

it('depth excludes measurement', function () use (&$builder): void {
    $builder->qubits(1)->h(0)->measure();

    expect($builder->depth())->toBe(1);
});

it('every Gate self-returning static factory has a matching GateType case', function (): void {
    $reflection = new ReflectionClass(Gate::class);

    $factoryNames = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
            static function (ReflectionMethod $method): bool {
                if ($method->getName() === 'fromArray') {
                    return false;
                }

                $returnType = $method->getReturnType();

                return $returnType instanceof ReflectionNamedType && $returnType->getName() === 'self';
            }
        )
    ));

    $enumValues = array_column(GateType::cases(), 'value');

    sort($factoryNames);
    sort($enumValues);

    expect($factoryNames)->toBe($enumValues);
});
