<?php

declare(strict_types=1);

use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Drivers\LocalSimulatorDriver;

dataset('concrete_drivers', [
    'local' => LocalSimulatorDriver::class,
    'aws' => AwsBraketDriver::class,
]);

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

    $this->createDriver = function (string $class) {
        if ($class === LocalSimulatorDriver::class) {
            return new LocalSimulatorDriver($this->bridge, ['backend' => 'statevector_simulator']);
        }

        return new AwsBraketDriver($this->bridge, [
            'region' => 'us-east-1',
            'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1',
            'bucket' => 'test-bucket',
        ]);
    };
});

it('implements QuantumDevice interface', function (string $driverClass) {
    $driver = ($this->createDriver)($driverClass);
    
    expect($driver)->toBeInstanceOf(QuantumDevice::class);
})->with('concrete_drivers');

it('implements AsynchronousDevice interface', function (string $driverClass) {
    $driver = ($this->createDriver)($driverClass);
    
    expect($driver)->toBeInstanceOf(AsynchronousDevice::class);
})->with('concrete_drivers');

it('returns correct byte length from generateEntropy', function (string $driverClass) {
    $driver = ($this->createDriver)($driverClass);
    
    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $driver->generateEntropy(16);

    expect(strlen($entropy))->toBe(2);
})->with('concrete_drivers');

it('converts bitstring to raw bytes correctly', function (string $driverClass) {
    $driver = ($this->createDriver)($driverClass);
    
    // '10110011' = 179 decimal = 0xB3
    // '10100101' = 165 decimal = 0xA5
    $this->bridge->method('execute')
        ->willReturn(['bits' => '1011001110100101']);

    $entropy = $driver->generateEntropy(16);

    expect($entropy)->toBe(chr(0xB3).chr(0xA5));
})->with('concrete_drivers');
