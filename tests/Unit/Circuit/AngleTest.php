<?php

declare(strict_types=1);

use Aether\Circuit\Angle;

// -------------------------------------------------------------------------
// Factory: rad
// -------------------------------------------------------------------------

it('rad creates angle from radians', function (): void {
    $angle = Angle::rad(1.5708);

    expect($angle->radians)->toBe(1.5708);
});

// -------------------------------------------------------------------------
// Factory: deg
// -------------------------------------------------------------------------

it('deg creates angle from degrees', function (): void {
    $angle = Angle::deg(180.0);

    expect($angle->radians)->toEqualWithDelta(M_PI, 1e-10);
});

it('deg converts 90 degrees to pi/2', function (): void {
    $angle = Angle::deg(90.0);

    expect($angle->radians)->toEqualWithDelta(M_PI / 2, 1e-10);
});

// -------------------------------------------------------------------------
// Factory: pi
// -------------------------------------------------------------------------

it('pi with no argument returns pi', function (): void {
    $angle = Angle::pi();

    expect($angle->radians)->toBe(M_PI);
});

it('pi with factor returns scaled value', function (): void {
    $angle = Angle::pi(0.5);

    expect($angle->radians)->toBe(M_PI * 0.5);
});

it('pi with factor 2 returns 2pi', function (): void {
    $angle = Angle::pi(2.0);

    expect($angle->radians)->toBe(M_PI * 2.0);
});

// -------------------------------------------------------------------------
// toDegrees
// -------------------------------------------------------------------------

it('toDegrees converts radians to degrees', function (): void {
    $angle = Angle::pi();

    expect($angle->toDegrees())->toEqualWithDelta(180.0, 1e-10);
});

it('toDegrees converts pi/2 to 90', function (): void {
    $angle = Angle::pi(0.5);

    expect($angle->toDegrees())->toEqualWithDelta(90.0, 1e-10);
});

// -------------------------------------------------------------------------
// Validation
// -------------------------------------------------------------------------

it('throws on NaN', function (): void {
    Angle::rad(NAN);
})->throws(InvalidArgumentException::class, 'finite');

it('throws on positive infinity', function (): void {
    Angle::rad(INF);
})->throws(InvalidArgumentException::class, 'finite');

it('throws on negative infinity', function (): void {
    Angle::rad(-INF);
})->throws(InvalidArgumentException::class, 'finite');

// -------------------------------------------------------------------------
// Immutability
// -------------------------------------------------------------------------

it('angle is immutable', function (): void {
    $angle = Angle::pi();

    expect(fn () => $angle->radians = 0.0) // @phpstan-ignore-line
        ->toThrow(Error::class);
});
