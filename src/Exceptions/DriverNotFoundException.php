<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a requested quantum driver is not registered or configured.
 */
class DriverNotFoundException extends AetherException
{
    /**
     * Create an exception for an unknown driver name.
     *
     * @param  string  $name
     */
    public static function forDriver(string $name): self
    {
        return new self("Quantum driver [{$name}] is not registered. Check your 'aether.driver' configuration.");
    }
}
