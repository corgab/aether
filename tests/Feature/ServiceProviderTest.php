<?php

declare(strict_types=1);

use Aether\AetherServiceProvider;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;
use Aether\Facades\Quantum;
use Aether\QuantumManager;
use Illuminate\Support\Facades\Artisan;

// -------------------------------------------------------------------------
// Service container registration
// -------------------------------------------------------------------------

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
// php artisan about
// -------------------------------------------------------------------------

it('registers an Aether section in php artisan about', function () {
    Artisan::call('about', ['--json' => true]);

    $output = json_decode(Artisan::output(), associative: true);

    expect($output)->toHaveKey('aether');
    expect($output['aether'])->toHaveKey('default_driver', 'local');
    expect($output['aether'])->toHaveKey('python_path', 'python3');
});
