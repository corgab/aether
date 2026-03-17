<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when the Python environment is not properly configured.
 */
class PythonEnvironmentException extends AetherException
{
    /**
     * Create an exception for a Python binary that cannot be located.
     *
     * @param  string  $path
     */
    public static function pythonNotFound(string $path): self
    {
        return new self("Python binary not found at [{$path}]. Check the 'python_path' configuration.");
    }

    /**
     * Create an exception for missing Python package dependencies.
     *
     * @param  string  $details
     */
    public static function missingDependencies(string $details): self
    {
        return new self("Required Python dependencies are missing: {$details}");
    }
}
