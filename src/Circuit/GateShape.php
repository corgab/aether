<?php

declare(strict_types=1);

namespace Aether\Circuit;

/**
 * The parameter shape shared by a group of gate types: which qubit-index
 * keys and angle keys appear in their wire representation, and in what order.
 *
 * Qubit keys always precede angle keys in the wire array — this ordering is
 * part of the `Gate::toArray()` contract and must not change.
 */
enum GateShape
{
    case Target;
    case TargetAngle;
    case ControlTarget;
    case ControlTargetAngle;
    case TwoTargets;
    case TwoTargetsAngle;
    case ControlTwoTargets;
    case TwoControlsTarget;
    case U;
    case Measure;

    /**
     * The qubit-index parameter keys for this shape, in wire order.
     *
     * @return string[]
     */
    public function qubitKeys(): array
    {
        return match ($this) {
            self::Target, self::TargetAngle, self::U => ['target'],
            self::ControlTarget, self::ControlTargetAngle => ['control', 'target'],
            self::TwoTargets, self::TwoTargetsAngle => ['target0', 'target1'],
            self::ControlTwoTargets => ['control', 'target0', 'target1'],
            self::TwoControlsTarget => ['control0', 'control1', 'target'],
            self::Measure => [],
        };
    }

    /**
     * The angle parameter keys for this shape, in wire order.
     *
     * @return string[]
     */
    public function angleKeys(): array
    {
        return match ($this) {
            self::TargetAngle, self::ControlTargetAngle, self::TwoTargetsAngle => ['angle'],
            self::U => ['theta', 'phi', 'lambda'],
            default => [],
        };
    }
}
