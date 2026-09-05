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
     * Create an exception for the "local" driver about to cache an
     * asynchronous result on the process-local array store while the queue
     * connection runs jobs across multiple processes.
     */
    public static function processLocalCacheStore(string $driver, string $queueDriver): self
    {
        return new self(
            "Driver [{$driver}] keeps asynchronous results in the default cache store, which is the process-local \"array\" store, while the queue connection uses the \"{$queueDriver}\" driver — the polling job would run in a different process and never see the result. Set drivers.{$driver}.cache_store (AETHER_LOCAL_CACHE_STORE) to a store shared across processes (\"file\", \"database\", \"redis\", \"memcached\"), or to \"array\" explicitly to accept the single-process limitation."
        );
    }
}
