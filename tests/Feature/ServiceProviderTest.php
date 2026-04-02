<?php

declare(strict_types=1);

use Aether\AetherServiceProvider;
use Aether\Circuit\CircuitBuilder;
use Aether\Entropy\EntropyGenerator;
use Aether\Facades\Quantum;
use Aether\QuantumManager;

// -------------------------------------------------------------------------
// Service container registration
// -------------------------------------------------------------------------

it('registers QuantumManager as a singleton in the container', function () {
    $first  = $this->app->make(QuantumManager::class);
    $second = $this->app->make(QuantumManager::class);

    expect($first)->toBeInstanceOf(QuantumManager::class)
        ->and($first)->toBe($second);
});

it('binds QuantumDevice contract to default driver', function () {
    $device = app(\Aether\Contracts\QuantumDevice::class);
    expect($device)->toBeInstanceOf(\Aether\Contracts\QuantumDevice::class);
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
