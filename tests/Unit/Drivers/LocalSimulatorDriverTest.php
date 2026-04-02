<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Results\CircuitResult;

// -------------------------------------------------------------------------
// Shared state
// -------------------------------------------------------------------------

$config = ['backend' => 'statevector_simulator'];

beforeEach(function () use ($config) {
    $this->bridge = $this->createMock(PythonExecutor::class);
    $this->bridge->method('bitstringToBytes')
        ->willReturnCallback(function (string $bitstring): string {
            $bytes = '';
            foreach (str_split($bitstring, 8) as $chunk) {
                $bytes .= chr((int) bindec($chunk));
            }

            return $bytes;
        });
    $this->config = $config;
    $this->driver = new LocalSimulatorDriver($this->bridge, $this->config);
});

// -------------------------------------------------------------------------
// Contract
// -------------------------------------------------------------------------

it('implements QuantumDevice interface', function () {
    expect($this->driver)->toBeInstanceOf(QuantumDevice::class);
});

// -------------------------------------------------------------------------
// executeCircuit()
// -------------------------------------------------------------------------

it('delegates executeCircuit to bridge with correct payload', function () {
    $circuitArray = [
        'qubits' => 2,
        'gates' => [['type' => 'H', 'target' => 0]],
        'shots' => 1024,
    ];

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->once())
        ->method('toArray')
        ->willReturn($circuitArray);

    $expectedPayload = array_merge($circuitArray, [
        'driver' => 'local',
        'driver_config' => $this->config,
    ]);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with('circuit.py', $expectedPayload, $this->config)
        ->willReturn(['counts' => ['00' => 512, '11' => 512]]);

    $result = $this->driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
    expect($result->counts())->toBe(['00' => 512, '11' => 512]);
});

it('returns CircuitResult with bridge counts', function () {
    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 75, '1' => 25]]);

    $result = $this->driver->executeCircuit($circuit);

    expect($result->counts())->toBe(['0' => 75, '1' => 25]);
});

// -------------------------------------------------------------------------
// generateEntropy()
// -------------------------------------------------------------------------

it('delegates generateEntropy to bridge with correct payload', function () {
    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'entropy.py',
            ['bits' => 16, 'driver' => 'local', 'driver_config' => $this->config],
            $this->config
        )
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $this->driver->generateEntropy(16);

    expect($entropy)->toBeString();
});

it('returns correct byte length from generateEntropy', function () {
    // 16 bits => 2 bytes
    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $this->driver->generateEntropy(16);

    expect(strlen($entropy))->toBe(2);
});

it('converts bitstring to raw bytes correctly', function () {
    // '10110011' = 179 decimal = 0xB3
    // '10100101' = 165 decimal = 0xA5
    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $this->driver->generateEntropy(16);

    expect($entropy)->toBe(chr(0xB3).chr(0xA5));
});
