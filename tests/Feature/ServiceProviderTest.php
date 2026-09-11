<?php

declare(strict_types=1);

use Aether\AetherServiceProvider;
use Aether\Circuit\CircuitBuilder;
use Aether\Config\AetherConfig;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Entropy\EntropyGenerator;
use Aether\Facades\Quantum;
use Aether\QuantumManager;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Support\Facades\Artisan;

// -------------------------------------------------------------------------
// Service container registration
// -------------------------------------------------------------------------

it('registers AetherConfig as a singleton in the container', function () {
    $first = $this->app->make(AetherConfig::class);
    $second = $this->app->make(AetherConfig::class);

    expect($first)->toBeInstanceOf(AetherConfig::class)
        ->and($first)->toBe($second);
});

it('builds drivers on a bare container that only binds config', function () {
    $container = new Container;
    $container->instance('config', new Repository(['aether' => ['python_path' => 'python3', 'drivers' => ['local' => []]]]));
    $container->instance(
        CacheRepositoryContract::class,
        new CacheRepository(new NullStore),
    );

    $manager = new QuantumManager($container);

    expect($manager->getDefaultDriver())->toBe('local')
        ->and($manager->driver('local'))->toBeInstanceOf(LocalSimulatorDriver::class);
});

it('resolves the default driver through AetherConfig', function () {
    config()->set('aether.default', '');

    expect(app(QuantumManager::class)->getDefaultDriver())->toBe('local');

    config()->set('aether.default', 'aws');

    expect(app(QuantumManager::class)->getDefaultDriver())->toBe('aws');
});

it('registers QuantumManager as a singleton in the container', function () {
    $first = $this->app->make(QuantumManager::class);
    $second = $this->app->make(QuantumManager::class);

    expect($first)->toBeInstanceOf(QuantumManager::class)
        ->and($first)->toBe($second);
});

it('binds QuantumDevice contract to default driver', function () {
    $device = app(QuantumDevice::class);
    expect($device)->toBeInstanceOf(QuantumDevice::class);
});

// -------------------------------------------------------------------------
// Configuration
// -------------------------------------------------------------------------

it('merges the aether config so that aether.default equals local', function () {
    $default = $this->app['config']->get('aether.default');

    expect($default)->toBe('local');
});

it('makes the aether.drivers config available', function () {
    $drivers = $this->app['config']->get('aether.drivers');

    expect($drivers)->toBeArray()
        ->and($drivers)->toHaveKey('local')
        ->and($drivers)->toHaveKey('aws');
});

// -------------------------------------------------------------------------
// Facade
// -------------------------------------------------------------------------

it('resolves the Quantum facade to a QuantumManager instance', function () {
    $resolved = Quantum::getFacadeRoot();

    expect($resolved)->toBeInstanceOf(QuantumManager::class);
});

it('returns a CircuitBuilder via the Quantum facade', function () {
    $builder = Quantum::circuit();

    expect($builder)->toBeInstanceOf(CircuitBuilder::class);
});

it('returns an EntropyGenerator via the Quantum facade', function () {
    $generator = Quantum::entropy();

    expect($generator)->toBeInstanceOf(EntropyGenerator::class);
});

// -------------------------------------------------------------------------
// Provider meta
// -------------------------------------------------------------------------

it('is a deferred provider that does not defer loading', function () {
    $provider = new AetherServiceProvider($this->app);

    expect($provider->isDeferred())->toBeFalse();
});

// -------------------------------------------------------------------------
// process_timeout config
// -------------------------------------------------------------------------

it('defaults aether.process_timeout to 300', function () {
    expect($this->app['config']->get('aether.process_timeout'))->toBe(300);
});

it('passes the configured process_timeout through to the driver bridge', function () {
    config()->set('aether.process_timeout', 45);

    $manager = new QuantumManager($this->app);
    $driver = $manager->driver('local');

    $bridge = (new ReflectionProperty($driver, 'bridge'))->getValue($driver);
    $timeout = (new ReflectionProperty($bridge, 'timeout'))->getValue($bridge);

    expect($timeout)->toBe(45);
});

// -------------------------------------------------------------------------
// max_qubits config
// -------------------------------------------------------------------------

it('defaults the local driver max_qubits to 25', function () {
    expect($this->app['config']->get('aether.drivers.local.max_qubits'))->toBe(25);
});

it('defaults the aws driver max_qubits to null', function () {
    expect($this->app['config']->get('aether.drivers.aws.max_qubits'))->toBeNull();
});

// -------------------------------------------------------------------------
// aws pricing / max_cost_per_run config
// -------------------------------------------------------------------------

it('defaults the aws driver pricing rates', function () {
    expect($this->app['config']->get('aether.drivers.aws.pricing'))->toBe([
        'per_task' => 0.30,
        'per_shot' => 0.00035,
        'currency' => 'USD',
    ]);
});

it('defaults the aws driver max_cost_per_run to null', function () {
    expect($this->app['config']->get('aether.drivers.aws.max_cost_per_run'))->toBeNull();
});

// -------------------------------------------------------------------------
// local task_ttl
// -------------------------------------------------------------------------

it('defaults the local driver task_ttl to one hour', function () {
    expect($this->app['config']->get('aether.drivers.local.task_ttl'))->toBe(3600);
});

// -------------------------------------------------------------------------
// php artisan about
// -------------------------------------------------------------------------

it('registers an Aether section in php artisan about', function () {
    Artisan::call('about', ['--json' => true]);

    $output = json_decode(Artisan::output(), associative: true);

    expect($output)->toHaveKey('aether');
    expect($output['aether'])->toHaveKey('default_driver', 'local');
    expect($output['aether'])->toHaveKey('python_path', 'python3');
});
