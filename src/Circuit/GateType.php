<?php

declare(strict_types=1);

namespace Aether\Circuit;

/**
 * Every quantum gate type supported by the wire protocol, keyed by its
 * lowercase wire type string (matches `Gate::toArray()['type']`).
 */
enum GateType: string
{
    case H = 'h';
    case X = 'x';
    case Y = 'y';
    case Z = 'z';
    case I = 'i';
    case S = 's';
    case SI = 'si';
    case T = 't';
    case TI = 'ti';
    case RX = 'rx';
    case RY = 'ry';
    case RZ = 'rz';
    case PhaseShift = 'phaseshift';
    case CNOT = 'cnot';
    case CZ = 'cz';
    case CY = 'cy';
    case CRX = 'crx';
    case CRY = 'cry';
    case CRZ = 'crz';
    case CPhaseShift = 'cphaseshift';
    case Swap = 'swap';
    case ISwap = 'iswap';
    case XX = 'xx';
    case YY = 'yy';
    case ZZ = 'zz';
    case CSwap = 'cswap';
    case CCNOT = 'ccnot';
    case U = 'u';
    case Measure = 'measure';

    /**
     * The parameter shape (qubit/angle key layout) for this gate type.
     */
    public function shape(): GateShape
    {
        return match ($this) {
            self::H, self::X, self::Y, self::Z, self::I,
            self::S, self::SI, self::T, self::TI => GateShape::Target,
            self::RX, self::RY, self::RZ, self::PhaseShift => GateShape::TargetAngle,
            self::CNOT, self::CZ, self::CY => GateShape::ControlTarget,
            self::CRX, self::CRY, self::CRZ, self::CPhaseShift => GateShape::ControlTargetAngle,
            self::Swap, self::ISwap => GateShape::TwoTargets,
            self::XX, self::YY, self::ZZ => GateShape::TwoTargetsAngle,
            self::CSwap => GateShape::ControlTwoTargets,
            self::CCNOT => GateShape::TwoControlsTarget,
            self::U => GateShape::U,
            self::Measure => GateShape::Measure,
        };
    }
}
