<?php

declare(strict_types=1);

namespace Aether\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed reader for the package-level `aether.*` settings.
 *
 * Every default lives here once, instead of being repeated at each
 * config('aether.x', default) call site, and every caller gets a value of
 * the documented type. Reads go to the config repository on each call, so a
 * value changed at runtime (or in a test via config()->set()) is honoured by
 * the next read; nothing is snapshotted.
 *
 * Per-driver options are not covered: they are typed by DriverConfig, built
 * by the driver itself from the array driver() returns.
 */
final class AetherConfig
{
    public const DEFAULT_DRIVER = 'local';

    public const DEFAULT_PYTHON_PATH = 'python3';

    public const DEFAULT_PROCESS_TIMEOUT = 300;

    public const DEFAULT_POLL_INTERVAL = 5;

    public const DEFAULT_MAX_POLL_ATTEMPTS = 720;

    public const DEFAULT_LOCAL_TASK_TTL = 3600;

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Name of the driver used when none is given (`aether.default`).
     *
     * A blank or non-string value falls back to the default, so a stray
     * `AETHER_DRIVER=` never resolves to an empty driver name.
     */
    public function defaultDriver(): string
    {
        return $this->string('aether.default') ?? self::DEFAULT_DRIVER;
    }

    /**
     * Python executable used to run the bin/python scripts (`aether.python_path`).
     */
    public function pythonPath(): string
    {
        return $this->string('aether.python_path') ?? self::DEFAULT_PYTHON_PATH;
    }

    /**
     * Seconds a Python subprocess may run before it is killed (`aether.process_timeout`).
     */
    public function processTimeout(): int
    {
        return $this->integer('aether.process_timeout', self::DEFAULT_PROCESS_TIMEOUT);
    }

    /**
     * Queue the asynchronous jobs run on, or null for the default queue (`aether.queue`).
     */
    public function queue(): ?string
    {
        return $this->string('aether.queue');
    }

    /**
     * Seconds between two status checks of an asynchronous task (`aether.poll_interval`).
     */
    public function pollInterval(): int
    {
        return $this->integer('aether.poll_interval', self::DEFAULT_POLL_INTERVAL);
    }

    /**
     * Number of status checks before the polling job gives up (`aether.max_poll_attempts`).
     */
    public function maxPollAttempts(): int
    {
        return $this->integer('aether.max_poll_attempts', self::DEFAULT_MAX_POLL_ATTEMPTS);
    }

    /**
     * Whether asynchronous tasks are mirrored into the quantum_tasks table (`aether.persist_tasks`).
     *
     * Accepts real booleans and the string spellings env() produces; anything
     * unrecognised counts as disabled, the safe default for a feature that
     * needs a migration to have run.
     */
    public function persistTasks(): bool
    {
        $value = $this->config->get('aether.persist_tasks', false);

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Seconds the local simulator keeps a dispatched result available to the
     * polling job (`aether.local_task_ttl`).
     */
    public function localTaskTtl(): int
    {
        return $this->integer('aether.local_task_ttl', self::DEFAULT_LOCAL_TASK_TTL);
    }

    /**
     * Raw options for one driver (`aether.drivers.<name>`), for the driver to type.
     *
     * @return array<string, mixed>
     */
    public function driver(string $name): array
    {
        $options = $this->config->get("aether.drivers.{$name}", []);

        return is_array($options) ? $options : [];
    }

    /**
     * Read a string option, trimmed, or null when blank or not a string.
     */
    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Read an integer option, falling back to $default when blank or not numeric.
     */
    private function integer(string $key, int $default): int
    {
        $value = $this->config->get($key);

        if (is_int($value)) {
            return $value;
        }

        $filtered = is_scalar($value) && ! is_bool($value)
            ? filter_var($value, FILTER_VALIDATE_INT)
            : false;

        return $filtered === false ? $default : $filtered;
    }
}
