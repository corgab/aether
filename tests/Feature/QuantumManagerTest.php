<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Entropy\EntropyGenerator;
use Aether\Exceptions\DriverNotFoundException;
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
    config()->set('aether.default', 'local');

    $manager = app(QuantumManager::class);

    expect($manager->circuit()->driverName())->toBe('local');
});
