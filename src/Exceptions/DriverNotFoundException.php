<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a requested quantum driver is not registered or configured.
 */
class DriverNotFoundException extends AetherException
{
    /**
     * Create an exception for a driver name a caller asked for explicitly.
     *
     * @param  list<string>  $available  The names that do resolve, so a typo is easy to spot.
     */
    public static function forDriver(string $name, array $available = []): self
    {
        $known = $available === []
            ? ''
            : ' Registered drivers: '.implode(', ', array_map(static fn (string $driver): string => "'{$driver}'", $available)).'.';

        return new self(
            "Quantum driver [{$name}] is not registered.{$known} Register a custom one with Quantum::extend('{$name}', ...) or check the name for a typo."
        );
    }

    /**
     * Create an exception for a default driver that resolves to nothing.
     */
    public static function forDefaultDriver(string $name): self
    {
        return new self(
            "Quantum driver [{$name}] is configured as the default but is not registered. Check the 'aether.default' setting (AETHER_DRIVER) in config/aether.php, or register the driver with Quantum::extend()."
        );
    }
}
