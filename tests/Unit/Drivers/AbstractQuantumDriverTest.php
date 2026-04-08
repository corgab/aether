<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AbstractQuantumDriver;
use Aether\Results\CircuitResult;

beforeEach(function () {
    $this->bridge = $this->createMock(PythonExecutor::class);
    $this->bridge->method('bitstringToBytes')
        ->willReturnCallback(function (string $bitstring): string {
            $bytes = '';
            foreach (str_split($bitstring, 8) as $chunk) {
                $bytes .= chr((int) bindec($chunk));
            }

            return $bytes;
        });

    $this->driver = new class($this->bridge, ['key' => 'value']) extends AbstractQuantumDriver
    {
        protected function driverName(): string
        {
            return 'test';
        }
    };
});

it('implements QuantumDevice', function () {
    expect($this->driver)->toBeInstanceOf(QuantumDevice::class);
});

it('passes driver name and config in circuit payload', function () {
    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'circuit.py',
            $this->callback(fn (array $p) => $p['driver'] === 'test' && $p['driver_config'] === ['key' => 'value']),
            ['key' => 'value']
        )
        ->willReturn(['counts' => ['0' => 500, '1' => 500]]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn([
        'qubits' => 1, 'gates' => [], 'shots' => 1000,
    ]);

    $result = $this->driver->executeCircuit($circuit);
    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('passes qubits and shots in entropy payload', function () {
    $driver = new class($this->bridge, ['key' => 'value', 'entropy_qubits' => 16]) extends AbstractQuantumDriver
    {
        protected function driverName(): string
        {
            return 'test';
        }
    };

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'entropy.py',
            $this->callback(function (array $p) {
                return $p['driver'] === 'test'
                    && $p['qubits'] === 16
                    && $p['shots'] === 1
                    && ! array_key_exists('bits', $p);
            }),
            ['key' => 'value', 'entropy_qubits' => 16]
        )
        ->willReturn(['bits' => str_repeat('1', 16)]);

    $bytes = $driver->generateEntropy(8);
    expect(strlen($bytes))->toBe(1);
});

it('computes shots dynamically based on requested bits', function () {
    $driver = new class($this->bridge, ['key' => 'value', 'entropy_qubits' => 16]) extends AbstractQuantumDriver
    {
        protected function driverName(): string
        {
            return 'test';
        }
    };

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'entropy.py',
            $this->callback(fn (array $p) => $p['qubits'] === 16 && $p['shots'] === 16),
            ['key' => 'value', 'entropy_qubits' => 16]
        )
        ->willReturn(['bits' => str_repeat('10', 128)]);

    $bytes = $driver->generateEntropy(256);
    expect(strlen($bytes))->toBe(32);
});

it('defaults to 16 qubits when entropy_qubits not in config', function () {
    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'entropy.py',
            $this->callback(fn (array $p) => $p['qubits'] === 16 && $p['shots'] === 1),
            ['key' => 'value']
        )
        ->willReturn(['bits' => str_repeat('1', 16)]);

    $bytes = $this->driver->generateEntropy(8);
    expect(strlen($bytes))->toBe(1);
});

it('calls beforeExecution hook', function () {
    $driver = new class($this->bridge, []) extends AbstractQuantumDriver
    {
        public bool $hookCalled = false;

        protected function driverName(): string
        {
            return 'hook';
        }

        protected function beforeExecution(): void
        {
            $this->hookCalled = true;
        }
    };

    $this->bridge->method('execute')
        ->willReturn(['counts' => ['0' => 1000]]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn([
        'qubits' => 1, 'gates' => [], 'shots' => 1000,
    ]);

    $driver->executeCircuit($circuit);
    expect($driver->hookCalled)->toBeTrue();
});
