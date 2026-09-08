<?php

declare(strict_types=1);

use Aether\Exceptions\AetherException;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\PythonEnvironmentException;
use Aether\Exceptions\QuantumExecutionException;

// -------------------------------------------------------------------------
// AetherException
// -------------------------------------------------------------------------

it('aether exception extends runtime exception', function (): void {
    expect(is_subclass_of(AetherException::class, RuntimeException::class))->toBeTrue();
});

// -------------------------------------------------------------------------
// QuantumExecutionException
// -------------------------------------------------------------------------

it('quantum execution exception extends aether exception', function (): void {
    expect(is_subclass_of(QuantumExecutionException::class, AetherException::class))->toBeTrue();
});

it('from python error includes script and stderr', function (): void {
    $exception = QuantumExecutionException::fromPythonError(
        'run_circuit.py',
        'Traceback: ModuleNotFoundError',
        1
    );

    expect($exception)
        ->toBeInstanceOf(QuantumExecutionException::class);
    expect($exception->getMessage())
        ->toContain('run_circuit.py')
        ->toContain('Traceback: ModuleNotFoundError');
    expect($exception->getCode())->toBe(1);
});

it('synchronous unsafe includes driver name', function (): void {
    $exception = QuantumExecutionException::synchronousUnsafe('braket');

    expect($exception)->toBeInstanceOf(QuantumExecutionException::class);
    expect($exception->getMessage())->toContain('braket');
});

// -------------------------------------------------------------------------
// PythonEnvironmentException
// -------------------------------------------------------------------------

it('python environment exception extends aether exception', function (): void {
    expect(is_subclass_of(PythonEnvironmentException::class, AetherException::class))->toBeTrue();
});

it('python not found includes path', function (): void {
    $exception = PythonEnvironmentException::pythonNotFound('/usr/bin/python3');

    expect($exception)->toBeInstanceOf(PythonEnvironmentException::class);
    expect($exception->getMessage())->toContain('/usr/bin/python3');
});

it('missing dependencies includes details', function (): void {
    $exception = PythonEnvironmentException::missingDependencies('qiskit>=1.0');

    expect($exception)->toBeInstanceOf(PythonEnvironmentException::class);
    expect($exception->getMessage())->toContain('qiskit>=1.0');
});

// -------------------------------------------------------------------------
// DriverNotFoundException
// -------------------------------------------------------------------------

it('driver not found exception extends aether exception', function (): void {
    expect(is_subclass_of(DriverNotFoundException::class, AetherException::class))->toBeTrue();
});

it('for driver includes driver name and points at registration, not at the default setting', function (): void {
    $exception = DriverNotFoundException::forDriver('braket');

    expect($exception)->toBeInstanceOf(DriverNotFoundException::class);
    expect($exception->getMessage())
        ->toContain('braket')
        ->toContain("Quantum::extend('braket'")
        ->not->toContain('aether.default');
});

it('for driver lists the registered drivers when given', function (): void {
    $exception = DriverNotFoundException::forDriver('ionk', ['local', 'aws', 'ionq']);

    expect($exception->getMessage())->toContain("Registered drivers: 'local', 'aws', 'ionq'.");
});

it('for default driver points at the aether.default setting', function (): void {
    $exception = DriverNotFoundException::forDefaultDriver('braket');

    expect($exception)->toBeInstanceOf(DriverNotFoundException::class);
    expect($exception->getMessage())
        ->toContain('braket')
        ->toContain('aether.default')
        ->toContain('AETHER_DRIVER');
});

// -------------------------------------------------------------------------
// InvalidCircuitException
// -------------------------------------------------------------------------

it('invalid circuit exception extends aether exception', function (): void {
    expect(is_subclass_of(InvalidCircuitException::class, AetherException::class))->toBeTrue();
});

it('no qubits returns meaningful message', function (): void {
    $exception = InvalidCircuitException::noQubits();

    expect($exception)->toBeInstanceOf(InvalidCircuitException::class);
    expect($exception->getMessage())->not->toBeEmpty();
});

it('gate target out of range includes gate target and qubit count', function (): void {
    $exception = InvalidCircuitException::gateTargetOutOfRange('H', 5, 3);

    expect($exception)->toBeInstanceOf(InvalidCircuitException::class);
    expect($exception->getMessage())
        ->toContain('H')
        ->toContain('5')
        ->toContain('3');
});

it('no measurement returns meaningful message', function (): void {
    $exception = InvalidCircuitException::noMeasurement();

    expect($exception)->toBeInstanceOf(InvalidCircuitException::class);
    expect($exception->getMessage())->not->toBeEmpty();
});

// -------------------------------------------------------------------------
// InvalidDriverConfigException
// -------------------------------------------------------------------------

it('invalid driver config exception extends aether exception', function (): void {
    expect(is_subclass_of(InvalidDriverConfigException::class, AetherException::class))->toBeTrue();
});

it('missing keys includes driver name and every missing key', function (): void {
    $exception = InvalidDriverConfigException::missingKeys('aws', ['region', 'device_arn']);

    expect($exception)->toBeInstanceOf(InvalidDriverConfigException::class);
    expect($exception->getMessage())
        ->toContain('aws')
        ->toContain('region')
        ->toContain('device_arn');
});
