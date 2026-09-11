<?php

declare(strict_types=1);

namespace Aether;

use Aether\Bridge\PythonBridge;
use Aether\Circuit\BatchBuilder;
use Aether\Circuit\CircuitBuilder;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Entropy\EntropyGenerator;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Results\CircuitResult;
use Aether\Testing\QuantumFake;
use Aether\Testing\ResultSequence;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Manages quantum backend driver resolution and caching.
 */
class QuantumManager extends Manager
{
    private ?QuantumFake $fakeInstance = null;

    /**
     * Return the name of the default driver from configuration.
     */
    public function getDefaultDriver(): string
    {
        // A null or blank aether.default (an empty AETHER_DRIVER= line) must
        // still resolve to a driver, not surface later as a TypeError.
        $default = $this->config->get('aether.default');

        if ($default instanceof BackedEnum) {
            $default = (string) $default->value;
        } elseif ($default instanceof UnitEnum) {
            $default = $default->name;
        }

        return is_string($default) && trim($default) !== '' ? trim($default) : 'local';
    }

    /**
     * Resolve the given driver, or the default when no name is provided.
     * Returns the fake instance when testing.
     */
    public function driver($driver = null)
    {
        if ($this->fakeInstance !== null) {
            return $this->fakeInstance->resolvedAs($this->driverAlias($driver));
        }

        return parent::driver($driver);
    }

    /**
     * The string alias for a driver argument, which Manager also accepts as an
     * enum; the fake reports this on the events it dispatches.
     */
    private function driverAlias(string|UnitEnum|null $driver): string
    {
        return match (true) {
            $driver === null => $this->getDefaultDriver(),
            $driver instanceof BackedEnum => (string) $driver->value,
            $driver instanceof UnitEnum => $driver->name,
            default => $driver,
        };
    }

    /**
     * Create a CircuitBuilder backed by the given (or default) driver.
     *
     * The resolved driver name is pinned onto the builder so a circuit that is
     * dispatched to the queue executes on the same backend it was built for,
     * even if the default driver changes before the job runs.
     */
    public function circuit(?string $driver = null): CircuitBuilder
    {
        return new CircuitBuilder(
            $this->driver($driver),
            $driver ?? $this->getDefaultDriver(),
        );
    }

    /**
     * Create a BatchBuilder backed by the given (or default) driver.
     *
     * @param  array<array-key, CircuitBuilder>  $circuits
     */
    public function batch(array $circuits, ?string $driver = null): BatchBuilder
    {
        return new BatchBuilder(
            $this->driver($driver),
            array_values($circuits),
            $driver ?? $this->getDefaultDriver(),
        );
    }

    /**
     * Create an EntropyGenerator backed by the given (or default) driver.
     */
    public function entropy(?string $driver = null): EntropyGenerator
    {
        return new EntropyGenerator($this->driver($driver));
    }

    /**
     * Replace all drivers with a QuantumFake for use in tests.
     *
     * Optionally stub what it returns, the same way Http::fake() does: a
     * canned counts array or CircuitResult, a closure evaluated per circuit,
     * or a ResultSequence built via QuantumFake::sequence(). See QuantumFake
     * for the full stubbing API (respondWith(), respondEntropyWith(), etc.).
     *
     * @param  array<string, int>|CircuitResult|Closure(CircuitBuilder): (array<string, int>|CircuitResult|null)|ResultSequence|null  $stub
     */
    public function fake(array|CircuitResult|Closure|ResultSequence|null $stub = null): QuantumFake
    {
        $fake = new QuantumFake($stub);
        $this->fakeInstance = $fake;
        $this->forgetDrivers();

        return $fake;
    }

    /**
     * Create a PythonBridge configured from the package configuration.
     *
     * Public so custom drivers registered through extend() can reuse the
     * same bridge wiring as the built-in drivers:
     *
     *     Quantum::extend('ionq', fn () => new IonqDriver(
     *         Quantum::bridge(),
     *         config('aether.drivers.ionq'),
     *     ));
     */
    public function bridge(): PythonBridge
    {
        return $this->createBridge();
    }

    /**
     * Resolve a driver by name, throwing DriverNotFoundException for unknown drivers.
     */
    protected function createDriver($driver)
    {
        // Manager has already turned an enum into its value, which may be an int.
        $name = (string) $driver;

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name);
        }

        $method = 'create'.Str::studly($name).'Driver';

        if ($method !== 'createDriver' && method_exists($this, $method)) {
            return $this->$method();
        }

        // Manager resolves a null argument to the default before calling us, so
        // an unknown name that equals the default points at configuration; any
        // other unknown name was asked for explicitly by the caller.
        throw $name === $this->getDefaultDriver()
            ? DriverNotFoundException::forDefaultDriver($name)
            : DriverNotFoundException::forDriver($name, $this->availableDrivers());
    }

    /**
     * The driver names that resolve today: the built-ins plus every extend()ed one.
     *
     * @return list<string>
     */
    private function availableDrivers(): array
    {
        $builtins = [];
        foreach (get_class_methods($this) as $method) {
            if ($method !== 'createDriver' && str_starts_with($method, 'create') && str_ends_with($method, 'Driver')) {
                $builtins[] = Str::snake(substr($method, 6, -6));
            }
        }

        return array_values(array_unique([
            ...$builtins,
            ...array_map(strval(...), array_keys($this->customCreators)),
        ]));
    }

    /**
     * Create a LocalSimulatorDriver instance.
     */
    protected function createLocalDriver(): LocalSimulatorDriver
    {
        $config = $this->config->get('aether.drivers.local', []);
        $config = is_array($config) ? $config : [];

        return new LocalSimulatorDriver(
            $this->createBridge(),
            $config,
            $this->container->make(CacheRepository::class),
        );
    }

    /**
     * Create an AwsBraketDriver instance.
     */
    protected function createAwsDriver(): AwsBraketDriver
    {
        return new AwsBraketDriver(
            $this->createBridge(),
            $this->config->get('aether.drivers.aws', []),
        );
    }

    /**
     * Create a PythonBridge configured with the python_path from config.
     */
    private function createBridge(): PythonBridge
    {
        return new PythonBridge(
            $this->config->get('aether.python_path', 'python3'),
            (int) $this->config->get('aether.process_timeout', 300),
        );
    }
}
