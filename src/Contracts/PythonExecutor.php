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
     * @param  array<mixed>  $payload
     * @param  array<string, mixed>  $driverConfig
     * @return array<mixed>
     */
    public function execute(string $script, array $payload, array $driverConfig = []): array;

    /**
     * Convert a binary digit string into raw bytes.
     */
    public function bitstringToBytes(string $bitstring): string;

    /**
     * Return the absolute path to the Python scripts directory.
     */
    public function scriptsPath(): string;
}
