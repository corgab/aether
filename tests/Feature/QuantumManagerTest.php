<?php

declare(strict_types=1);

use Aether\Circuit\BatchBuilder;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Entropy\EntropyGenerator;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\QuantumManager;

it('resolves the default driver as LocalSimulatorDriver', function () {
    expect(app(QuantumManager::class)->driver())->toBeInstanceOf(LocalSimulatorDriver::class);
});

it('resolves the local driver by name', function () {
    expect(app(QuantumManager::class)->driver('local'))->toBeInstanceOf(LocalSimulatorDriver::class);
});

it('resolves the aws driver by name', function () {
    expect(app(QuantumManager::class)->driver('aws'))->toBeInstanceOf(AwsBraketDriver::class);
});

it('blames the driver name, not the default setting, when an explicit driver is unknown', function () {
    config(['aether.default' => 'local']);

    try {
        app(QuantumManager::class)->driver('ionq');
        $this->fail('Expected DriverNotFoundException.');
    } catch (DriverNotFoundException $e) {
        expect($e->getMessage())
            ->toContain("Quantum::extend('ionq'")
            ->not->toContain('aether.default');
    }
});

it('names the extended drivers in the message for an unknown explicit driver', function () {
    $manager = app(QuantumManager::class);
    $manager->extend('ionq', fn () => Mockery::mock(QuantumDevice::class));

    try {
        $manager->driver('ionk');
        $this->fail('Expected DriverNotFoundException.');
    } catch (DriverNotFoundException $e) {
        expect($e->getMessage())->toContain("'ionq'");
    }
});

it('falls back to the local driver when aether.default is null or blank', function (mixed $default) {
    config(['aether.default' => $default]);

    expect(app(QuantumManager::class)->driver())->toBeInstanceOf(LocalSimulatorDriver::class);
})->with(['null' => [null], 'blank' => ['']]);

it('still throws DriverNotFoundException for an unknown driver when aether.default is null', function () {
    config(['aether.default' => null]);

    expect(fn () => app(QuantumManager::class)->driver('ionq'))->toThrow(DriverNotFoundException::class);
});

it('blames the aether.default setting when the configured default driver is unknown', function () {
    config(['aether.default' => 'ionq']);

    try {
        app(QuantumManager::class)->driver();
        $this->fail('Expected DriverNotFoundException.');
    } catch (DriverNotFoundException $e) {
        expect($e->getMessage())->toContain('aether.default');
    }
});

it('throws DriverNotFoundException for unknown driver', function () {
    app(QuantumManager::class)->driver('unknown');
})->throws(DriverNotFoundException::class);

it('caches driver instances', function () {
    $manager = app(QuantumManager::class);
    expect($manager->driver('local'))->toBe($manager->driver('local'));
});

it('returns a CircuitBuilder for the default driver', function () {
    expect(app(QuantumManager::class)->circuit())->toBeInstanceOf(CircuitBuilder::class);
});

it('returns an EntropyGenerator for a named driver', function () {
    expect(app(QuantumManager::class)->entropy('aws'))->toBeInstanceOf(EntropyGenerator::class);
});

it('returns a BatchBuilder for the default driver', function () {
    $manager = app(QuantumManager::class);
    $circuit = $manager->circuit()->qubits(1)->measure();

    expect($manager->batch([$circuit]))->toBeInstanceOf(BatchBuilder::class);
});

it('runs a batch through the faked default driver', function () {
    $manager = app(QuantumManager::class);
    $fake = $manager->fake();
    $circuit = $manager->circuit()->qubits(1)->measure();

    $result = $manager->batch([$circuit])->run();

    expect($result)->toHaveCount(1);
    $fake->assertBatchRan();
});

it('rejects a batch containing a circuit pinned to another driver', function () {
    $manager = app(QuantumManager::class);
    $manager->fake();
    $circuit = $manager->circuit('aws')->qubits(1)->measure();

    $manager->batch([$circuit], 'local');
})->throws(InvalidCircuitException::class);

it('registers and resolves a custom driver via extend()', function () {
    $manager = app(QuantumManager::class);
    $stub = $this->createMock(QuantumDevice::class);
    $manager->extend('custom', fn () => $stub);
    expect($manager->driver('custom'))->toBe($stub);
});

it('fake returns same instance for any driver name', function () {
    $manager = app(QuantumManager::class);
    $fake = $manager->fake();
    expect($manager->driver('local'))->toBe($fake);
    expect($manager->driver('aws'))->toBe($fake);
    expect($manager->driver('anything'))->toBe($fake);
});

it('fake threads a stub through to the QuantumFake it creates', function () {
    $manager = app(QuantumManager::class);
    $fake = $manager->fake(['00' => 700, '11' => 324]);
    $circuit = $manager->circuit()->qubits(2)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['00' => 700, '11' => 324]);
});

it('custom driver takes precedence over built-in', function () {
    $manager = app(QuantumManager::class);
    $stub = $this->createMock(QuantumDevice::class);
    $manager->extend('local', fn () => $stub);
    $manager->forgetDrivers();
    expect($manager->driver('local'))->toBe($stub);
});

it('forgets all cached drivers', function () {
    $manager = app(QuantumManager::class);
    $a = $manager->driver('local');
    $manager->forgetDrivers();
    $b = $manager->driver('local');
    expect($a)->not->toBe($b);
});

it('names the driver on circuits it builds so dispatched jobs target the same backend', function () {
    $manager = app(QuantumManager::class);

    expect($manager->circuit('aws')->driverName())->toBe('aws');
});

it('pins the resolved default driver name when no driver is requested', function () {
    config()->set('aether.default', 'aws');

    $manager = app(QuantumManager::class);

    expect($manager->circuit()->driverName())->toBe('aws');
});

it('pins the resolved default driver name on batches when no driver is requested', function () {
    config()->set('aether.default', 'aws');

    $manager = app(QuantumManager::class);
    $batch = $manager->batch([$manager->circuit()->qubits(1)->h(0)->measure()]);

    expect($batch->driverName())->toBe('aws');
});

it('resolves the local driver with a custom cache store when configured', function () {
    config()->set('cache.stores.custom_array', ['driver' => 'array']);
    config()->set('aether.drivers.local.cache_store', 'custom_array');

    $manager = app(QuantumManager::class);
    expect($manager->driver('local'))->toBeInstanceOf(LocalSimulatorDriver::class);
});

it('throws InvalidDriverConfigException when the configured cache store cannot be resolved', function () {
    config()->set('aether.drivers.local.cache_store', 'nonexistent_store');

    $manager = app(QuantumManager::class);
    expect(fn () => $manager->driver('local'))
        ->toThrow(InvalidDriverConfigException::class, 'cannot resolve cache store [nonexistent_store]');
});

enum TestQuantumDriverEnum: string
{
    case Aws = 'aws';
    case Local = 'local';
}

enum TestUnitQuantumDriverEnum
{
    case local;
}

it('resolves backed and unit enums when creating circuits, batches, entropy, and drivers', function () {
    $manager = app(QuantumManager::class);

    expect($manager->driver(TestQuantumDriverEnum::Local))->toBeInstanceOf(LocalSimulatorDriver::class);
    expect($manager->driver(TestUnitQuantumDriverEnum::local))->toBeInstanceOf(LocalSimulatorDriver::class);

    $circuit = $manager->circuit(TestQuantumDriverEnum::Aws);
    expect($circuit->driverName())->toBe('aws');

    $circuitUnit = $manager->circuit(TestUnitQuantumDriverEnum::local);
    expect($circuitUnit->driverName())->toBe('local');

    $batch = $manager->batch([$manager->circuit('aws')->qubits(1)->h(0)->measure()], TestQuantumDriverEnum::Aws);
    expect($batch->driverName())->toBe('aws');

    $entropy = $manager->entropy(TestQuantumDriverEnum::Local);
    expect($entropy)->toBeInstanceOf(EntropyGenerator::class);
});
