<?php

declare(strict_types=1);

namespace Aether\Circuit;

/**
 * Immutable value object representing an angle for parametric quantum gates.
 */
final readonly class Angle
{
    private function __construct(public float $radians)
    {
        if (! is_finite($radians)) {
            throw new \InvalidArgumentException('Angle must be a finite number.');
        }
    }

    /**
     * Create an angle from a value in radians.
     */
    public static function rad(float $radians): self
    {
        return new self($radians);
    }

    /**
     * Create an angle from a value in degrees.
     */
    public static function deg(float $degrees): self
    {
        return new self($degrees * M_PI / 180.0);
    }

    /**
     * Create an angle as a multiple of pi.
     *
     * Angle::pi() returns pi. Angle::pi(0.5) returns pi/2.
     */
    public static function pi(float $factor = 1.0): self
    {
        return new self(M_PI * $factor);
    }

    /**
     * Convert this angle to degrees.
     */
    public function toDegrees(): float
    {
        return $this->radians * 180.0 / M_PI;
    }
}
