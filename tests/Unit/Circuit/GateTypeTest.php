<?php

declare(strict_types=1);

use Aether\Circuit\GateShape;
use Aether\Circuit\GateType;

// -------------------------------------------------------------------------
// GateShape: qubitKeys() / angleKeys()
// -------------------------------------------------------------------------

it('resolves qubit keys per shape', function (GateShape $shape, array $expected): void {
    expect($shape->qubitKeys())->toBe($expected);
})->with([
    'Target' => [GateShape::Target, ['target']],
    'TargetAngle' => [GateShape::TargetAngle, ['target']],
    'ControlTarget' => [GateShape::ControlTarget, ['control', 'target']],
    'ControlTargetAngle' => [GateShape::ControlTargetAngle, ['control', 'target']],
    'TwoTargets' => [GateShape::TwoTargets, ['target0', 'target1']],
    'TwoTargetsAngle' => [GateShape::TwoTargetsAngle, ['target0', 'target1']],
    'ControlTwoTargets' => [GateShape::ControlTwoTargets, ['control', 'target0', 'target1']],
    'TwoControlsTarget' => [GateShape::TwoControlsTarget, ['control0', 'control1', 'target']],
    'U' => [GateShape::U, ['target']],
    'Measure' => [GateShape::Measure, []],
]);

it('resolves angle keys per shape', function (GateShape $shape, array $expected): void {
    expect($shape->angleKeys())->toBe($expected);
})->with([
    'Target' => [GateShape::Target, []],
    'TargetAngle' => [GateShape::TargetAngle, ['angle']],
    'ControlTarget' => [GateShape::ControlTarget, []],
    'ControlTargetAngle' => [GateShape::ControlTargetAngle, ['angle']],
    'TwoTargets' => [GateShape::TwoTargets, []],
    'TwoTargetsAngle' => [GateShape::TwoTargetsAngle, ['angle']],
    'ControlTwoTargets' => [GateShape::ControlTwoTargets, []],
    'TwoControlsTarget' => [GateShape::TwoControlsTarget, []],
    'U' => [GateShape::U, ['theta', 'phi', 'lambda']],
    'Measure' => [GateShape::Measure, []],
]);

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
// GateType: paramKeys() order
// -------------------------------------------------------------------------

it('resolves paramKeys() in qubitKeys + angleKeys wire order', function (GateType $type, array $expected): void {
    expect($type->paramKeys())->toBe($expected);
})->with([
    'h' => [GateType::H, ['target']],
    'cnot' => [GateType::CNOT, ['control', 'target']],
    'rx' => [GateType::RX, ['target', 'angle']],
    'crx' => [GateType::CRX, ['control', 'target', 'angle']],
    'swap' => [GateType::Swap, ['target0', 'target1']],
    'xx' => [GateType::XX, ['target0', 'target1', 'angle']],
    'cswap' => [GateType::CSwap, ['control', 'target0', 'target1']],
    'ccnot' => [GateType::CCNOT, ['control0', 'control1', 'target']],
    'u' => [GateType::U, ['target', 'theta', 'phi', 'lambda']],
    'measure' => [GateType::Measure, ['targets']],
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
