<?php

declare(strict_types=1);

namespace Aether;

use Aether\Bridge\PythonBridge;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Entropy\EntropyGenerator;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Testing\QuantumFake;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigContract;

/**
 * Manages quantum backend driver resolution and caching.
 */
class QuantumManager
{
    /** @var array<string, QuantumDevice> */
    private array $drivers = [];

    /** @var array<string, Closure> */
    private array $customCreators = [];

    /**
     * @param  ConfigContract  $config
     */
    public function __construct(private readonly ConfigContract $config) {}

    /**
     * Resolve the given driver, or the default driver when no name is provided.
     *
     * @param  string|null  $name
     *
     * @throws DriverNotFoundException
     */
    public function driver(?string $name = null): QuantumDevice
    {
        $name = $name ?? $this->getDefaultDriver();

        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Create a CircuitBuilder backed by the given (or default) driver.
     *
     * @param  string|null  $driver
     */
    public function circuit(?string $driver = null): CircuitBuilder
    {
        return new CircuitBuilder($this->driver($driver));
    }

    /**
     * Create an EntropyGenerator backed by the given (or default) driver.
     *
     * @param  string|null  $driver
     */
    public function entropy(?string $driver = null): EntropyGenerator
    {
        return new EntropyGenerator($this->driver($driver));
    }

    /**
     * Register a custom driver creator closure.
     *
     * @param  string  $name
     * @param  Closure  $callback
     */
    public function extend(string $name, Closure $callback): void
    {
        $this->customCreators[$name] = $callback;
    }

    /**
     * Replace all drivers with a QuantumFake for use in tests.
     */
    public function fake(): Testing\QuantumFake
    {
        $fake = new Testing\QuantumFake();
        $this->drivers = [];
        $this->customCreators = [];
        $this->extend('local', fn () => $fake);
        $this->extend('aws', fn () => $fake);

        return $fake;
    }

    /**
     * Return the name of the default driver from configuration.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('aether.default', 'local');
    }

    /**
     * Resolve a driver instance by name.
     *
     * @param  string  $name
     *
     * @throws DriverNotFoundException
     */
    private function resolve(string $name): QuantumDevice
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])();
        }

        /** @var array<string, mixed>|null $driverConfig */
        $driverConfig = $this->config->get("aether.drivers.{$name}");

        if ($driverConfig === null) {
            throw DriverNotFoundException::forDriver($name);
        }

        $bridge = $this->createBridge();

        return match ($name) {
            'local' => new LocalSimulatorDriver($bridge, $driverConfig),
            'aws' => new AwsBraketDriver($bridge, $driverConfig),
            default => throw DriverNotFoundException::forDriver($name),
        };
    }

    /**
     * Create a PythonBridge instance configured with the python_path from config.
     */
    private function createBridge(): PythonBridge
    {
        return new PythonBridge(
            (string) $this->config->get('aether.python_path', 'python3'),
        );
    }
}
