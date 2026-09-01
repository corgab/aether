<?php

declare(strict_types=1);

use Aether\Circuit\Angle;
use Aether\Circuit\Gate;
use Aether\Circuit\GateType;
use Aether\Exceptions\InvalidCircuitException;

// -------------------------------------------------------------------------
// Factory: single-target gates
// -------------------------------------------------------------------------

it('h creates hadamard gate', function (): void {
    $gate = Gate::h(0);

    expect($gate->type)->toBe('h');
    expect($gate->params)->toBe(['target' => 0]);
});

it('x creates pauli x gate', function (): void {
    $gate = Gate::x(2);

    expect($gate->type)->toBe('x');
    expect($gate->params)->toBe(['target' => 2]);
});

it('y creates pauli y gate', function (): void {
    $gate = Gate::y(1);

    expect($gate->type)->toBe('y');
    expect($gate->params)->toBe(['target' => 1]);
});

it('z creates pauli z gate', function (): void {
    $gate = Gate::z(3);

    expect($gate->type)->toBe('z');
    expect($gate->params)->toBe(['target' => 3]);
});

// -------------------------------------------------------------------------
// Factory: phase gates
// -------------------------------------------------------------------------

it('s creates phase s gate', function (): void {
    $gate = Gate::s(0);

    expect($gate->type)->toBe('s');
    expect($gate->params)->toBe(['target' => 0]);
});

it('t creates phase t gate', function (): void {
    $gate = Gate::t(1);

    expect($gate->type)->toBe('t');
    expect($gate->params)->toBe(['target' => 1]);
});

// -------------------------------------------------------------------------
// Factory: parametric gates
// -------------------------------------------------------------------------

it('rx creates rotation x gate with Angle', function (): void {
    $gate = Gate::rx(0, Angle::pi(0.5));

    expect($gate->type)->toBe('rx');
    expect($gate->params['target'])->toBe(0);
    expect($gate->params['angle'])->toEqualWithDelta(M_PI / 2, 1e-10);
});

it('ry creates rotation y gate with float', function (): void {
    $gate = Gate::ry(1, 1.5708);

    expect($gate->type)->toBe('ry');
    expect($gate->params['target'])->toBe(1);
    expect($gate->params['angle'])->toBe(1.5708);
});

it('rz creates rotation z gate with Angle degrees', function (): void {
    $gate = Gate::rz(2, Angle::deg(90.0));

    expect($gate->type)->toBe('rz');
    expect($gate->params['target'])->toBe(2);
    expect($gate->params['angle'])->toEqualWithDelta(M_PI / 2, 1e-10);
});

// -------------------------------------------------------------------------
// Factory: controlled gates
// -------------------------------------------------------------------------

it('cnot creates controlled not gate', function (): void {
    $gate = Gate::cnot(0, 1);

    expect($gate->type)->toBe('cnot');
    expect($gate->params)->toBe(['control' => 0, 'target' => 1]);
});

it('cz creates controlled z gate', function (): void {
    $gate = Gate::cz(0, 1);

    expect($gate->type)->toBe('cz');
    expect($gate->params)->toBe(['control' => 0, 'target' => 1]);
});

// -------------------------------------------------------------------------
// Factory: multi-qubit gates
// -------------------------------------------------------------------------

it('swap creates swap gate', function (): void {
    $gate = Gate::swap(0, 1);

    expect($gate->type)->toBe('swap');
    expect($gate->params)->toBe(['target0' => 0, 'target1' => 1]);
});

it('ccnot creates toffoli gate', function (): void {
    $gate = Gate::ccnot(0, 1, 2);

    expect($gate->type)->toBe('ccnot');
    expect($gate->params)->toBe(['control0' => 0, 'control1' => 1, 'target' => 2]);
});

// -------------------------------------------------------------------------
// Factory: measure gate
// -------------------------------------------------------------------------

it('measure with no args keeps null targets', function (): void {
    $gate = Gate::measure();

    expect($gate->type)->toBe('measure');
    expect($gate->params)->toBe(['targets' => null]);
});

it('measure with int wraps in array', function (): void {
    $gate = Gate::measure(2);

    expect($gate->type)->toBe('measure');
    expect($gate->params)->toBe(['targets' => [2]]);
});

it('measure with array keeps array', function (): void {
    $gate = Gate::measure([0, 1, 2]);

    expect($gate->type)->toBe('measure');
    expect($gate->params)->toBe(['targets' => [0, 1, 2]]);
});

// -------------------------------------------------------------------------
// toArray — flat serialization
// -------------------------------------------------------------------------

it('to array for single target gate', function (): void {
    expect(Gate::h(0)->toArray())->toBe(['type' => 'h', 'target' => 0]);
});

it('to array for cnot gate', function (): void {
    expect(Gate::cnot(0, 1)->toArray())->toBe(['type' => 'cnot', 'control' => 0, 'target' => 1]);
});

it('to array for cz gate', function (): void {
    expect(Gate::cz(0, 1)->toArray())->toBe(['type' => 'cz', 'control' => 0, 'target' => 1]);
});

it('to array for swap gate', function (): void {
    expect(Gate::swap(0, 1)->toArray())->toBe(['type' => 'swap', 'target0' => 0, 'target1' => 1]);
});

it('to array for ccnot gate', function (): void {
    expect(Gate::ccnot(0, 1, 2)->toArray())->toBe(['type' => 'ccnot', 'control0' => 0, 'control1' => 1, 'target' => 2]);
});

it('to array for rx gate serializes angle as float', function (): void {
    $arr = Gate::rx(0, Angle::pi())->toArray();

    expect($arr['type'])->toBe('rx');
    expect($arr['target'])->toBe(0);
    expect($arr['angle'])->toBe(M_PI);
});

it('to array for measure gate with targets', function (): void {
    expect(Gate::measure([0, 1])->toArray())->toBe(['type' => 'measure', 'targets' => [0, 1]]);
});

it('to array for measure gate with null', function (): void {
    expect(Gate::measure()->toArray())->toBe(['type' => 'measure', 'targets' => null]);
});

// -------------------------------------------------------------------------
// Immutability
// -------------------------------------------------------------------------

it('gate is immutable', function (): void {
    $gate = Gate::h(0);

    expect(fn () => $gate->type = 'x') // @phpstan-ignore-line
        ->toThrow(Error::class);
});

it('crx creates controlled rotation x gate', function (): void {
    $gate = Gate::crx(0, 1, Angle::pi(0.5));
    expect($gate->type)->toBe('crx');
    expect($gate->params['control'])->toBe(0);
    expect($gate->params['target'])->toBe(1);
    expect($gate->params['angle'])->toEqualWithDelta(M_PI / 2, 1e-10);
});

it('cphaseshift creates controlled phase shift gate', function (): void {
    $gate = Gate::cphaseshift(0, 1, M_PI);
    expect($gate->type)->toBe('cphaseshift');
    expect($gate->params['control'])->toBe(0);
    expect($gate->params['target'])->toBe(1);
    expect($gate->params['angle'])->toEqualWithDelta(M_PI, 1e-10);
});

it('u creates U gate', function (): void {
    $gate = Gate::u(0, 1.0, 2.0, 3.0);
    expect($gate->type)->toBe('u');
    expect($gate->params['target'])->toBe(0);
    expect($gate->params['theta'])->toBe(1.0);
    expect($gate->params['phi'])->toBe(2.0);
    expect($gate->params['lambda'])->toBe(3.0);
});

it('cswap creates Fredkin gate', function (): void {
    $gate = Gate::cswap(0, 1, 2);
    expect($gate->type)->toBe('cswap');
    expect($gate->params)->toBe(['control' => 0, 'target0' => 1, 'target1' => 2]);
});

it('iswap creates iSWAP gate', function (): void {
    $gate = Gate::iswap(0, 1);
    expect($gate->type)->toBe('iswap');
    expect($gate->params)->toBe(['target0' => 0, 'target1' => 1]);
});

it('xx creates XX gate', function (): void {
    $gate = Gate::xx(0, 1, 1.57);
    expect($gate->type)->toBe('xx');
    expect($gate->params)->toBe(['target0' => 0, 'target1' => 1, 'angle' => 1.57]);
});

it('cry creates controlled rotation y gate', function (): void {
    $gate = Gate::cry(0, 1, 1.57);
    expect($gate->type)->toBe('cry');
    expect($gate->params)->toBe(['control' => 0, 'target' => 1, 'angle' => 1.57]);
});

it('crz creates controlled rotation z gate', function (): void {
    $gate = Gate::crz(0, 1, 1.57);
    expect($gate->type)->toBe('crz');
    expect($gate->params)->toBe(['control' => 0, 'target' => 1, 'angle' => 1.57]);
});

it('phaseshift creates phase shift gate', function (): void {
    $gate = Gate::phaseshift(0, 1.57);
    expect($gate->type)->toBe('phaseshift');
    expect($gate->params)->toBe(['target' => 0, 'angle' => 1.57]);
});

it('yy creates yy gate', function (): void {
    $gate = Gate::yy(0, 1, 1.57);
    expect($gate->type)->toBe('yy');
    expect($gate->params)->toBe(['target0' => 0, 'target1' => 1, 'angle' => 1.57]);
});

it('zz creates zz gate', function (): void {
    $gate = Gate::zz(0, 1, 1.57);
    expect($gate->type)->toBe('zz');
    expect($gate->params)->toBe(['target0' => 0, 'target1' => 1, 'angle' => 1.57]);
});

it('throws InvalidArgumentException when given NAN', function (): void {
    expect(fn () => Gate::rx(0, NAN))->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException when given INF', function (): void {
    expect(fn () => Gate::u(0, INF, 0.0, 0.0))->toThrow(InvalidArgumentException::class);
});

// -------------------------------------------------------------------------
// fromArray() — metadata-driven round trip for every gate type
// -------------------------------------------------------------------------

it('round trips every gate type through fromArray/toArray', function (GateType $type): void {
    $shape = $type->shape();
    $definition = ['type' => $type->value];

    foreach ($shape->qubitKeys() as $index => $key) {
        $definition[$key] = $index;
    }

    $angleValues = [0.5, 0.25, 0.125];
    foreach ($shape->angleKeys() as $index => $key) {
        $definition[$key] = $angleValues[$index];
    }

    expect(Gate::fromArray($definition)->toArray())->toBe($definition);
})->with(array_filter(GateType::cases(), fn (GateType $type): bool => $type !== GateType::Measure));

it('round trips a measure gate with explicit targets through fromArray/toArray', function (): void {
    $definition = ['type' => 'measure', 'targets' => [0, 2]];

    expect(Gate::fromArray($definition)->toArray())->toBe($definition);
});

it('round trips a measure gate with null targets through fromArray/toArray', function (): void {
    $definition = ['type' => 'measure', 'targets' => null];

    expect(Gate::fromArray($definition)->toArray())->toBe($definition);
});

// -------------------------------------------------------------------------
// fromArray() — error handling
// -------------------------------------------------------------------------

it('fromArray throws for an unknown gate type', function (): void {
    expect(fn () => Gate::fromArray(['type' => 'not-a-real-gate', 'target' => 0]))
        ->toThrow(InvalidCircuitException::class);
});

it('fromArray throws when the type key is not a string', function (): void {
    expect(fn () => Gate::fromArray(['type' => 42, 'target' => 0]))
        ->toThrow(InvalidCircuitException::class);
});

it('fromArray throws when the type key is missing', function (): void {
    expect(fn () => Gate::fromArray(['target' => 0]))
        ->toThrow(InvalidCircuitException::class);
});

it('fromArray throws missingGateParameter when a qubit key is missing', function (): void {
    expect(fn () => Gate::fromArray(['type' => 'cnot', 'control' => 0]))
        ->toThrow(InvalidCircuitException::class, 'missing required parameter [target]');
});

it('fromArray throws missingGateParameter when an angle key is missing', function (): void {
    expect(fn () => Gate::fromArray(['type' => 'rx', 'target' => 0]))
        ->toThrow(InvalidCircuitException::class, 'missing required parameter [angle]');
});

// -------------------------------------------------------------------------
// qubitIndices()
// -------------------------------------------------------------------------

it('qubitIndices returns the qubit-index params for non-measure gates', function (): void {
    expect(Gate::cnot(0, 1)->qubitIndices())->toBe([0, 1]);
    expect(Gate::ccnot(0, 1, 2)->qubitIndices())->toBe([0, 1, 2]);
    expect(Gate::rx(3, 1.0)->qubitIndices())->toBe([3]);
});

it('qubitIndices returns explicit targets for a measure gate', function (): void {
    expect(Gate::measure([0, 2])->qubitIndices())->toBe([0, 2]);
});

it('qubitIndices returns an empty array for a measure-all gate', function (): void {
    expect(Gate::measure()->qubitIndices())->toBe([]);
});
