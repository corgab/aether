<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

// -------------------------------------------------------------------------
// Shared state
// -------------------------------------------------------------------------

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
    $this->config = [
        'region' => 'us-east-1',
        'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1', 'bucket' => 'test-bucket',
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
        'gates' => [['type' => 'H', 'target' => 0]],
        'shots' => 1024,
    ];

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->once())
        ->method('toArray')
        ->willReturn($circuitArray);

    $expectedPayload = array_merge($circuitArray, [
        'driver' => 'aws',
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
    $config = ['region' => 'us-east-1', 'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1', 'bucket' => 'test-bucket'];
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
            ['qubits' => 16, 'shots' => 1, 'driver' => 'aws', 'driver_config' => $this->config],
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

    expect($entropy)->toBe(chr(0xB3).chr(0xA5));
});

// -------------------------------------------------------------------------
// Config validation (fail fast, before Python)
// -------------------------------------------------------------------------

it('throws InvalidDriverConfigException when device_arn is missing', function () {
    $driver = new AwsBraketDriver($this->bridge, ['region' => 'us-east-1', 'bucket' => 'test-bucket']);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->never())->method('toArray');
    $this->bridge->expects($this->never())->method('execute');

    try {
        $driver->executeCircuit($circuit);
        $this->fail('Expected InvalidDriverConfigException was not thrown.');
    } catch (InvalidDriverConfigException $e) {
        expect($e->getMessage())->toContain('device_arn');
        expect(strtolower($e->getMessage()))->toContain('aws');
    }
});

it('throws InvalidDriverConfigException when region is an empty string', function () {
    $config = ['region' => '   ', 'device_arn' => 'arn:aws:braket:::device/x', 'bucket' => 'test-bucket'];
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->executeCircuit($circuit))
        ->toThrow(InvalidDriverConfigException::class, 'region');
});

it('lists every missing required key in the exception message', function () {
    $driver = new AwsBraketDriver($this->bridge, ['synchronous_safe' => true]);

    try {
        $driver->generateEntropy(16);
        $this->fail('Expected InvalidDriverConfigException was not thrown.');
    } catch (InvalidDriverConfigException $e) {
        expect($e->getMessage())->toContain('region');
        expect($e->getMessage())->toContain('device_arn');
        expect($e->getMessage())->toContain('bucket');
    }
});

it('validates config on generateEntropy as well as executeCircuit', function () {
    $driver = new AwsBraketDriver($this->bridge, ['region' => 'us-east-1', 'bucket' => 'test-bucket']);

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->generateEntropy(16))
        ->toThrow(InvalidDriverConfigException::class, 'device_arn');
});

// -------------------------------------------------------------------------
// AsynchronousDevice: submitCircuit()
// -------------------------------------------------------------------------

it('implements AsynchronousDevice interface', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    expect($driver)->toBeInstanceOf(AsynchronousDevice::class);
});

it('submits the circuit and returns the task arn', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

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
        'driver' => 'aws',
        'driver_config' => $this->config,
    ]);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with('submit.py', $expectedPayload, $this->config)
        ->willReturn(['task_arn' => 'arn:aws:braket:us-east-1:123456789012:quantum-task/abc']);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toBe('arn:aws:braket:us-east-1:123456789012:quantum-task/abc');
});

it('submitCircuit succeeds even when synchronous_safe is false', function () {
    $config = array_merge($this->config, ['synchronous_safe' => false]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with('submit.py', $this->anything(), $config)
        ->willReturn(['task_arn' => 'arn:aws:braket:us-east-1:123456789012:quantum-task/async']);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toBe('arn:aws:braket:us-east-1:123456789012:quantum-task/async');

    // The regression this guards against: the same driver, same config,
    // must still refuse a *synchronous* run.
    $circuit2 = $this->createMock(CircuitBuilder::class);
    $circuit2->expects($this->never())->method('toArray');

    expect(fn () => $driver->executeCircuit($circuit2))
        ->toThrow(QuantumExecutionException::class);
});

it('throws InvalidDriverConfigException on submitCircuit when required config is missing', function () {
    $driver = new AwsBraketDriver($this->bridge, ['region' => 'us-east-1', 'bucket' => 'test-bucket']);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->never())->method('toArray');
    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(InvalidDriverConfigException::class, 'device_arn');
});

it('throws QuantumExecutionException when submit.py response is missing task_arn', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn([]);

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(QuantumExecutionException::class, 'task_arn');
});

it('throws QuantumExecutionException when submit.py returns an empty task_arn', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['task_arn' => '   ']);

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(QuantumExecutionException::class, 'task_arn');
});

it('throws QuantumExecutionException when submit.py returns a non-string task_arn', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['task_arn' => 12345]);

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(QuantumExecutionException::class, 'task_arn');
});

// -------------------------------------------------------------------------
// AsynchronousDevice: checkTask()
// -------------------------------------------------------------------------

it('sends the correct payload and script name to checkTask', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'check.py',
            ['task_arn' => 'arn:aws:braket:us-east-1:123456789012:quantum-task/abc', 'driver' => 'aws', 'driver_config' => $this->config],
            $this->config
        )
        ->willReturn(['status' => 'RUNNING']);

    $snapshot = $driver->checkTask('arn:aws:braket:us-east-1:123456789012:quantum-task/abc');

    expect($snapshot)->toBeInstanceOf(TaskSnapshot::class);
    expect($snapshot->status)->toBe(TaskStatus::Running);
});

it('checkTask succeeds even when synchronous_safe is false', function () {
    $config = array_merge($this->config, ['synchronous_safe' => false]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $this->bridge->method('execute')->willReturn(['status' => 'RUNNING']);

    $snapshot = $driver->checkTask('arn:...');

    expect($snapshot->status)->toBe(TaskStatus::Running);
});

it('throws InvalidDriverConfigException on checkTask when required config is missing', function () {
    $driver = new AwsBraketDriver($this->bridge, ['region' => 'us-east-1', 'bucket' => 'test-bucket']);

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->checkTask('arn:...'))
        ->toThrow(InvalidDriverConfigException::class, 'device_arn');
});

it('maps each Braket state to the right TaskStatus', function (string $braketState, TaskStatus $expected) {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn(['status' => $braketState]);

    $snapshot = $driver->checkTask('arn:...');

    expect($snapshot->status)->toBe($expected);
})->with([
    ['CREATED', TaskStatus::Created],
    ['QUEUED', TaskStatus::Queued],
    ['RUNNING', TaskStatus::Running],
    ['COMPLETED', TaskStatus::Completed],
    ['FAILED', TaskStatus::Failed],
    ['CANCELLED', TaskStatus::Cancelled],
]);

it('returns counts only when the task has completed', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn(['status' => 'RUNNING']);

    $snapshot = $driver->checkTask('arn:...');

    expect($snapshot->counts)->toBeNull();
});

it('returns counts when the task has completed', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn([
        'status' => 'COMPLETED',
        'counts' => ['00' => 512, '11' => 512],
    ]);

    $snapshot = $driver->checkTask('arn:...');

    expect($snapshot->status)->toBe(TaskStatus::Completed);
    expect($snapshot->counts)->toBe(['00' => 512, '11' => 512]);
});

it('throws QuantumExecutionException when check.py status is missing', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn([]);

    expect(fn () => $driver->checkTask('arn:...'))
        ->toThrow(QuantumExecutionException::class, 'status');
});

it('throws QuantumExecutionException when check.py status is unknown', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn(['status' => 'BOGUS']);

    expect(fn () => $driver->checkTask('arn:...'))
        ->toThrow(QuantumExecutionException::class, 'status');
});
