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
use Aether\Testing\QuantumFake;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;

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
            return $this->fakeInstance;
        }

        return parent::driver($driver);
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
     */
    public function fake(): QuantumFake
    {
        $fake = new QuantumFake;
        $this->fakeInstance = $fake;
        $this->forgetDrivers();

        return $fake;
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
        return new LocalSimulatorDriver(
            $this->createBridge(),
            $this->config->get('aether.drivers.local', []),
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
