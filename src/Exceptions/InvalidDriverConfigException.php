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

    /**
     * Create an exception for a config key holding a value of the wrong shape.
     */
    public static function invalidValue(string $driver, string $key, mixed $value, string $expected): self
    {
        $given = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self(
            "Driver [{$driver}] has an invalid value for [{$key}]: expected {$expected}, got {$given}. Set it in config/aether.php under drivers.{$driver}."
        );
    }
}
