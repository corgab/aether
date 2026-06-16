<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a driver is used with missing or invalid configuration.
 */
class InvalidDriverConfigException extends AetherException
{
    /**
     * Create an exception for a driver missing one or more required config keys.
     *
     * @param  string[]  $missingKeys
     */
    public static function missingKeys(string $driver, array $missingKeys): self
    {
        $keys = implode(', ', $missingKeys);

        return new self(
            "Driver [{$driver}] is missing required configuration: {$keys}. Set these in config/aether.php under drivers.{$driver}."
        );
    }
}
