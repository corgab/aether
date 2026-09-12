<?php

declare(strict_types=1);

use Aether\Circuit\GateShape;
use Aether\Circuit\GateType;

// -------------------------------------------------------------------------
// GateType: case count
// -------------------------------------------------------------------------

it('has exactly 29 gate types', function (): void {
    expect(GateType::cases())->toHaveCount(29);
});

// -------------------------------------------------------------------------
// GateType: shape() mapping
// -------------------------------------------------------------------------

it('maps each gate type to its shape', function (GateType $type, GateShape $shape): void {
    expect($type->shape())->toBe($shape);
})->with([
    'h' => [GateType::H, GateShape::Target],
    'x' => [GateType::X, GateShape::Target],
    'y' => [GateType::Y, GateShape::Target],
    'z' => [GateType::Z, GateShape::Target],
    'i' => [GateType::I, GateShape::Target],
    's' => [GateType::S, GateShape::Target],
    'si' => [GateType::SI, GateShape::Target],
    't' => [GateType::T, GateShape::Target],
    'ti' => [GateType::TI, GateShape::Target],
    'rx' => [GateType::RX, GateShape::TargetAngle],
    'ry' => [GateType::RY, GateShape::TargetAngle],
    'rz' => [GateType::RZ, GateShape::TargetAngle],
    'phaseshift' => [GateType::PhaseShift, GateShape::TargetAngle],
    'cnot' => [GateType::CNOT, GateShape::ControlTarget],
    'cz' => [GateType::CZ, GateShape::ControlTarget],
    'cy' => [GateType::CY, GateShape::ControlTarget],
    'crx' => [GateType::CRX, GateShape::ControlTargetAngle],
    'cry' => [GateType::CRY, GateShape::ControlTargetAngle],
    'crz' => [GateType::CRZ, GateShape::ControlTargetAngle],
    'cphaseshift' => [GateType::CPhaseShift, GateShape::ControlTargetAngle],
    'swap' => [GateType::Swap, GateShape::TwoTargets],
    'iswap' => [GateType::ISwap, GateShape::TwoTargets],
    'xx' => [GateType::XX, GateShape::TwoTargetsAngle],
    'yy' => [GateType::YY, GateShape::TwoTargetsAngle],
    'zz' => [GateType::ZZ, GateShape::TwoTargetsAngle],
    'cswap' => [GateType::CSwap, GateShape::ControlTwoTargets],
    'ccnot' => [GateType::CCNOT, GateShape::TwoControlsTarget],
    'u' => [GateType::U, GateShape::U],
    'measure' => [GateType::Measure, GateShape::Measure],
]);

// -------------------------------------------------------------------------
// GateType: wire values match case backing strings
// -------------------------------------------------------------------------

it('backs every case with its lowercase wire type string', function (): void {
    $expected = [
        'h', 'x', 'y', 'z', 'i', 's', 'si', 't', 'ti',
        'rx', 'ry', 'rz', 'phaseshift',
        'cnot', 'cz', 'cy',
        'crx', 'cry', 'crz', 'cphaseshift',
        'swap', 'iswap',
        'xx', 'yy', 'zz',
        'cswap', 'ccnot', 'u', 'measure',
    ];

    $actual = array_column(GateType::cases(), 'value');

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
});
