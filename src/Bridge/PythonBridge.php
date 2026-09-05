<?php

declare(strict_types=1);

namespace Aether\Bridge;

use Aether\Contracts\PythonExecutor;
use Aether\Exceptions\PythonEnvironmentException;
use Aether\Exceptions\PythonProcessTimedOutException;
use Aether\Exceptions\QuantumExecutionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Execute Python quantum scripts via the Symfony Process component.
 */
class PythonBridge implements PythonExecutor
{
    private readonly string $scriptsPath;

    public function __construct(
        private readonly string $pythonPath,
        private readonly int $timeout = 300,
    ) {
        $resolved = realpath(__DIR__.'/../../bin/python');

        $this->scriptsPath = $resolved !== false
            ? $resolved
            : __DIR__.'/../../bin/python';
    }

    /**
     * Execute a Python script with the given payload.
     *
     * @param  array<mixed>  $payload
     * @param  array<string, mixed>  $driverConfig
     * @return array<mixed>
     *
     * @throws PythonEnvironmentException
     * @throws QuantumExecutionException
     */
    public function execute(string $script, array $payload, array $driverConfig = []): array
    {
        $scriptPath = $this->scriptsPath.DIRECTORY_SEPARATOR.$script;

        $process = new Process(
            command: [$this->pythonPath, $scriptPath],
            env: $this->buildEnvironment($driverConfig),
        );

        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // ProcessTimedOutException extends the same RuntimeException caught
            // below, so it must be caught first — otherwise a timeout would be
            // misreported as a missing Python binary.
            throw PythonProcessTimedOutException::afterSeconds($script, $this->timeout);
        } catch (ProcessRuntimeException) {
            throw PythonEnvironmentException::pythonNotFound($this->pythonPath);
        }

        $exitCode = $process->getExitCode() ?? 1;

        // Exit codes 126 (cannot execute) and 127 (command not found) indicate
        // that the Python binary itself could not be launched by the OS shell.
        if (in_array($exitCode, [126, 127], strict: true)) {
            throw PythonEnvironmentException::pythonNotFound($this->pythonPath);
        }

        if (! $process->isSuccessful()) {
            throw QuantumExecutionException::fromPythonError(
                $script,
                $this->extractErrorMessage($process->getErrorOutput()),
                $exitCode,
            );
        }

        try {
            $decoded = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw QuantumExecutionException::fromPythonError(
                $script,
                'Invalid JSON output: '.$e->getMessage(),
                0,
            );
        }

        if (! is_array($decoded)) {
            throw QuantumExecutionException::fromPythonError(
                $script,
                'Expected JSON object, got '.get_debug_type($decoded),
                0,
            );
        }

        return $decoded;
    }

    /**
     * Extract a human-readable error message from a failed process's stderr.
     *
     * Python scripts write {"error": "message"} to stderr on failure. When
     * stderr is valid JSON containing an "error" key, the bare message is
     * returned; otherwise the raw stderr is returned unchanged.
     */
    private function extractErrorMessage(string $stderr): string
    {
        try {
            $decoded = json_decode($stderr, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $stderr;
        }

        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        return $stderr;
    }

    /**
     * Return the absolute path to the Python scripts directory.
     */
    public function scriptsPath(): string
    {
        return $this->scriptsPath;
    }

    /**
     * Convert a binary digit string (e.g. "10110011") into raw bytes.
     */
    public function bitstringToBytes(string $bitstring): string
    {
        $bytes = '';

        foreach (str_split($bitstring, 8) as $chunk) {
            // & 0xFF keeps the value in chr()'s 0-255 range (each chunk is at
            // most 8 bits, so this is a no-op for valid input).
            $bytes .= chr(((int) bindec($chunk)) & 0xFF);
        }

        return $bytes;
    }

    /**
     * Build the environment variable array for the child process.
     *
     * Only non-null values are included to preserve boto3's credential chain.
     *
     * @param  array<string, mixed>  $driverConfig
     * @return array<string, string>
     */
    public function buildEnvironment(array $driverConfig): array
    {
        $map = [
            'region' => 'AWS_DEFAULT_REGION',
            'bucket' => 'AETHER_S3_BUCKET',
            'device_arn' => 'AETHER_DEVICE_ARN',
        ];

        $env = [];

        foreach ($map as $configKey => $envKey) {
            if (isset($driverConfig[$configKey])) {
                $env[$envKey] = (string) $driverConfig[$configKey];
            }
        }

        return $env;
    }
}
