<?php declare(strict_types=1);

use Aether\Bridge\PythonBridge;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;

// -------------------------------------------------------------------------
// Shared state
// -------------------------------------------------------------------------

beforeEach(function () {
    $this->bridge = $this->createMock(PythonBridge::class);
    $this->bridge->method('bitstringToBytes')
        ->willReturnCallback(function (string $bitstring): string {
            $bytes = '';
            foreach (str_split($bitstring, 8) as $chunk) {
                $bytes .= chr((int) bindec($chunk));
            }
            return $bytes;
        });
    $this->config = [
        'region'           => 'us-east-1',
        'device_arn'       => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1',
        'synchronous_safe' => true,
    ];
});

// -------------------------------------------------------------------------
// Contract
// -------------------------------------------------------------------------

it('implements QuantumDevice interface', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    expect($driver)->toBeInstanceOf(QuantumDevice::class);
});

// -------------------------------------------------------------------------
// executeCircuit()
// -------------------------------------------------------------------------

it('passes aws driver key in executeCircuit payload', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuitArray = [
        'qubits' => 2,
        'gates'  => [['type' => 'H', 'target' => 0]],
        'shots'  => 1024,
    ];

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->once())
        ->method('toArray')
        ->willReturn($circuitArray);

    $expectedPayload = array_merge($circuitArray, [
        'driver'        => 'aws',
        'driver_config' => $this->config,
    ]);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with('circuit.py', $expectedPayload, $this->config)
        ->willReturn(['counts' => ['00' => 512, '11' => 512]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
    expect($result->counts())->toBe(['00' => 512, '11' => 512]);
});

it('throws QuantumExecutionException when synchronous_safe is false on executeCircuit', function () {
    $config = array_merge($this->config, ['synchronous_safe' => false]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    try {
        $driver->executeCircuit($circuit);
        $this->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e)->toBeInstanceOf(QuantumExecutionException::class);
        expect(strtolower($e->getMessage()))->toContain('aws');
    }
});

it('works when synchronous_safe defaults to true on executeCircuit', function () {
    // Config without 'synchronous_safe' key — should default to true (safe)
    $config = ['region' => 'us-east-1'];
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

// -------------------------------------------------------------------------
// generateEntropy()
// -------------------------------------------------------------------------

it('delegates generateEntropy to bridge with aws driver', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'entropy.py',
            ['bits' => 16, 'driver' => 'aws', 'driver_config' => $this->config],
            $this->config
        )
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $driver->generateEntropy(16);

    expect($entropy)->toBeString();
});

it('returns correct byte length from generateEntropy', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $driver->generateEntropy(16);

    expect(strlen($entropy))->toBe(2);
});

it('throws QuantumExecutionException when synchronous_safe is false on generateEntropy', function () {
    $config = array_merge($this->config, ['synchronous_safe' => false]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $this->bridge->expects($this->never())->method('execute');

    try {
        $driver->generateEntropy(16);
        $this->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e)->toBeInstanceOf(QuantumExecutionException::class);
        expect(strtolower($e->getMessage()))->toContain('aws');
    }
});

it('converts bitstring to raw bytes correctly in generateEntropy', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    // '10110011' = 179 decimal = 0xB3
    // '10100101' = 165 decimal = 0xA5
    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $driver->generateEntropy(16);

    expect($entropy)->toBe(chr(0xB3) . chr(0xA5));
});
