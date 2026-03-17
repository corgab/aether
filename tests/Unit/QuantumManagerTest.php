<?php declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Exceptions\DriverNotFoundException;
use Aether\QuantumManager;
use Illuminate\Config\Repository;

// -------------------------------------------------------------------------
// Shared state
// -------------------------------------------------------------------------

beforeEach(function () {
    $this->config = new Repository([
        'aether' => [
            'default'     => 'local',
            'python_path' => 'python3',
            'drivers'     => [
                'local' => [
                    'backend'          => 'default',
                    'synchronous_safe' => true,
                ],
                'aws' => [
                    'region'           => 'us-east-1',
                    'bucket'           => 'test',
                    'device_arn'       => 'arn:...',
                    'synchronous_safe' => true,
                ],
            ],
        ],
    ]);
});

// -------------------------------------------------------------------------
// Default driver
// -------------------------------------------------------------------------

it('resolves the default driver as LocalSimulatorDriver', function () {
    $manager = new QuantumManager($this->config);

    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(LocalSimulatorDriver::class);
});

// -------------------------------------------------------------------------
// Named drivers
// -------------------------------------------------------------------------

it('resolves the local driver by name', function () {
    $manager = new QuantumManager($this->config);

    $driver = $manager->driver('local');

    expect($driver)->toBeInstanceOf(LocalSimulatorDriver::class);
});

it('resolves the aws driver by name', function () {
    $manager = new QuantumManager($this->config);

    $driver = $manager->driver('aws');

    expect($driver)->toBeInstanceOf(AwsBraketDriver::class);
});

// -------------------------------------------------------------------------
// Unknown driver
// -------------------------------------------------------------------------

it('throws DriverNotFoundException for unknown driver', function () {
    $manager = new QuantumManager($this->config);

    $manager->driver('unknown');
})->throws(DriverNotFoundException::class);

// -------------------------------------------------------------------------
// Instance caching
// -------------------------------------------------------------------------

it('caches driver instances', function () {
    $manager = new QuantumManager($this->config);

    $first  = $manager->driver('local');
    $second = $manager->driver('local');

    expect($first)->toBe($second);
});

// -------------------------------------------------------------------------
// circuit()
// -------------------------------------------------------------------------

it('returns a CircuitBuilder for the default driver', function () {
    $manager = new QuantumManager($this->config);

    $builder = $manager->circuit();

    expect($builder)->toBeInstanceOf(CircuitBuilder::class);
});

it('returns a CircuitBuilder for a named driver', function () {
    $manager = new QuantumManager($this->config);

    $builder = $manager->circuit('aws');

    expect($builder)->toBeInstanceOf(CircuitBuilder::class);
});

// -------------------------------------------------------------------------
// extend()
// -------------------------------------------------------------------------

it('registers and resolves a custom driver via extend()', function () {
    $manager = new QuantumManager($this->config);

    $fake = $this->createMock(QuantumDevice::class);

    $manager->extend('fake', static function () use ($fake): QuantumDevice {
        return $fake;
    });

    $resolved = $manager->driver('fake');

    expect($resolved)->toBe($fake);
});

it('custom driver takes precedence over built-in driver', function () {
    $fake = $this->createMock(QuantumDevice::class);

    // Use a fresh manager to avoid stale cache from extend() on the built-in 'local'
    $freshManager = new QuantumManager($this->config);
    $freshManager->extend('local', static function () use ($fake): QuantumDevice {
        return $fake;
    });

    $resolved = $freshManager->driver('local');

    expect($resolved)->toBe($fake);
});
