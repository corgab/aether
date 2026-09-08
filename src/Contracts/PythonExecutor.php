<?php

declare(strict_types=1);

namespace Aether\Contracts;

/**
 * Contract for executing Python quantum scripts.
 */
interface PythonExecutor
{
    /**
     * Execute a Python script with the given payload.
     *
     * Driver settings are part of the payload (`driver_config`); implementations
     * must not need a second channel for them.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function execute(string $script, array $payload): array;

    /**
     * Convert a binary digit string into raw bytes.
     */
    public function bitstringToBytes(string $bitstring): string;

    /**
     * Return the absolute path to the Python scripts directory.
     */
    public function scriptsPath(): string;
}
