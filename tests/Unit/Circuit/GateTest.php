<?php declare(strict_types=1);

use Aether\Circuit\Gate;

// -------------------------------------------------------------------------
// Factory: single-target gates
// -------------------------------------------------------------------------

it('h creates hadamard gate', function (): void {
    $gate = Gate::h(0);

    expect($gate->type)->toBe('h');
    expect($gate->target)->toBe(0);
    expect($gate->control)->toBeNull();
    expect($gate->targets)->toBeNull();
});

it('x creates pauli x gate', function (): void {
    $gate = Gate::x(2);

    expect($gate->type)->toBe('x');
    expect($gate->target)->toBe(2);
    expect($gate->control)->toBeNull();
    expect($gate->targets)->toBeNull();
});

it('y creates pauli y gate', function (): void {
    $gate = Gate::y(1);

    expect($gate->type)->toBe('y');
    expect($gate->target)->toBe(1);
    expect($gate->control)->toBeNull();
    expect($gate->targets)->toBeNull();
});

it('z creates pauli z gate', function (): void {
    $gate = Gate::z(3);

    expect($gate->type)->toBe('z');
    expect($gate->target)->toBe(3);
    expect($gate->control)->toBeNull();
    expect($gate->targets)->toBeNull();
});

// -------------------------------------------------------------------------
// Factory: controlled gate
// -------------------------------------------------------------------------

it('cnot creates controlled not gate', function (): void {
    $gate = Gate::cnot(0, 1);

    expect($gate->type)->toBe('cnot');
    expect($gate->control)->toBe(0);
    expect($gate->target)->toBe(1);
    expect($gate->targets)->toBeNull();
});

// -------------------------------------------------------------------------
// Factory: measure gate
// -------------------------------------------------------------------------

it('measure with null keeps null targets', function (): void {
    $gate = Gate::measure(null);

    expect($gate->type)->toBe('measure');
    expect($gate->targets)->toBeNull();
    expect($gate->target)->toBeNull();
    expect($gate->control)->toBeNull();
});

it('measure with int wraps in array', function (): void {
    $gate = Gate::measure(2);

    expect($gate->type)->toBe('measure');
    expect($gate->targets)->toBe([2]);
    expect($gate->target)->toBeNull();
    expect($gate->control)->toBeNull();
});

it('measure with array keeps array', function (): void {
    $gate = Gate::measure([0, 1, 2]);

    expect($gate->type)->toBe('measure');
    expect($gate->targets)->toBe([0, 1, 2]);
    expect($gate->target)->toBeNull();
    expect($gate->control)->toBeNull();
});

// -------------------------------------------------------------------------
// toArray
// -------------------------------------------------------------------------

it('to array for single target gate', function (): void {
    $gate = Gate::h(0);

    expect($gate->toArray())->toBe(['type' => 'h', 'target' => 0]);
});

it('to array for cnot gate', function (): void {
    $gate = Gate::cnot(0, 1);

    expect($gate->toArray())->toBe(['type' => 'cnot', 'control' => 0, 'target' => 1]);
});

it('to array for measure gate with targets', function (): void {
    $gate = Gate::measure([0, 1]);

    expect($gate->toArray())->toBe(['type' => 'measure', 'targets' => [0, 1]]);
});

it('to array for measure gate with null', function (): void {
    $gate = Gate::measure(null);

    expect($gate->toArray())->toBe(['type' => 'measure', 'targets' => null]);
});

it('to array for x gate', function (): void {
    $gate = Gate::x(3);

    expect($gate->toArray())->toBe(['type' => 'x', 'target' => 3]);
});

it('to array for y gate', function (): void {
    $gate = Gate::y(1);

    expect($gate->toArray())->toBe(['type' => 'y', 'target' => 1]);
});

it('to array for z gate', function (): void {
    $gate = Gate::z(4);

    expect($gate->toArray())->toBe(['type' => 'z', 'target' => 4]);
});

// -------------------------------------------------------------------------
// Immutability: verify readonly properties cannot be modified
// -------------------------------------------------------------------------

it('gate is immutable', function (): void {
    $gate = Gate::h(0);

    expect(fn () => $gate->type = 'x') // @phpstan-ignore-line
        ->toThrow(Error::class);
});
