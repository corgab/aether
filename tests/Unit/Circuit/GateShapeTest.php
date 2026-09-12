<?php

declare(strict_types=1);

use Aether\Circuit\GateShape;

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
