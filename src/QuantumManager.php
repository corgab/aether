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
        return $this->config->get('aether.default', 'local');
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
    public function bridge(?string $pythonPath = null): PythonBridge
    {
        return $this->createBridge($pythonPath);
    }

    /**
     * Create the local simulator driver over the given interpreter, or over
     * the configured one when none is given.
     *
     * Bypasses the fake and the resolved-driver cache on purpose: this is how
     * aether:install verifies an interpreter it has just created, with the
     * exact wiring the application will use afterwards.
     */
    public function localDriver(?string $pythonPath = null): LocalSimulatorDriver
    {
        return new LocalSimulatorDriver(
            $this->createBridge($pythonPath),
            $this->config->get('aether.drivers.local', []),
        );
    }

    /**
     * Resolve a driver by name, throwing DriverNotFoundException for unknown drivers.
     */
    protected function createDriver($driver)
    {
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = 'create'.Str::studly($driver).'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw DriverNotFoundException::forDriver($driver);
    }

    /**
     * Create a LocalSimulatorDriver instance.
     */
    protected function createLocalDriver(): LocalSimulatorDriver
    {
        return $this->localDriver();
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
     * Create a PythonBridge over the given interpreter, or over the
     * python_path from config when none is given.
     */
    private function createBridge(?string $pythonPath = null): PythonBridge
    {
        return new PythonBridge(
            $pythonPath ?? $this->config->get('aether.python_path', 'python3'),
            (int) $this->config->get('aether.process_timeout', 300),
        );
    }
}
