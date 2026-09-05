<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    // submitCircuit()/checkTask() go through the Cache facade and the global
    // config() helper. Neither requires a full Laravel app: an ArrayStore-backed
    // cache repository is swapped into the facade, and a bare container carries
    // a minimal config repository for the config() helper to resolve.
    Cache::swap(new CacheRepository(new ArrayStore));
    Container::setInstance(tap(new Container, function (Container $container) {
        $container->instance('config', new ConfigRepository(['aether' => ['local_task_ttl' => 3600]]));
    }));
});

afterEach(function () {
    Container::setInstance(null);
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
            ['qubits' => 16, 'shots' => 1, 'driver' => 'local', 'driver_config' => $this->config],
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

// -------------------------------------------------------------------------
// AsynchronousDevice: simulated local async
// -------------------------------------------------------------------------

it('implements AsynchronousDevice interface', function () {
    expect($this->driver)->toBeInstanceOf(AsynchronousDevice::class);
});

it('submitCircuit returns a synthetic local: identifier', function () {
    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $taskArn = $this->driver->submitCircuit($circuit);

    expect($taskArn)->toBeString();
    expect($taskArn)->toStartWith('local:');
});

it('submit then check round-trips the measurement counts', function () {
    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 75, '1' => 25]]);

    $taskArn = $this->driver->submitCircuit($circuit);
    $snapshot = $this->driver->checkTask($taskArn);

    expect($snapshot)->toBeInstanceOf(TaskSnapshot::class);
    expect($snapshot->status)->toBe(TaskStatus::Completed);
    expect($snapshot->counts)->toBe(['0' => 75, '1' => 25]);
});

it('submitCircuit still runs the circuit synchronously through the bridge', function () {
    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->expects($this->once())
        ->method('toArray')
        ->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->expects($this->once())
        ->method('execute')
        ->with('circuit.py', $this->anything(), $this->config)
        ->willReturn(['counts' => ['0' => 100]]);

    $this->driver->submitCircuit($circuit);
});

it('checkTask reports Failed for an unknown task key', function () {
    $snapshot = $this->driver->checkTask('local:'.Str::uuid());

    expect($snapshot)->toBeInstanceOf(TaskSnapshot::class);
    expect($snapshot->status)->toBe(TaskStatus::Failed);
    expect($snapshot->counts)->toBeNull();
});

it('checkTask explains why an unknown task key failed', function () {
    $taskArn = 'local:'.Str::uuid();

    $snapshot = $this->driver->checkTask($taskArn);

    expect($snapshot->error)
        ->toContain($taskArn)
        ->toContain('may have expired');
});

it('checkTask rejects a task arn that is not in local: form', function () {
    expect(fn () => $this->driver->checkTask('arn:aws:braket:us-east-1:123456789012:quantum-task/abc'))
        ->toThrow(QuantumExecutionException::class);
});

// -------------------------------------------------------------------------
// max_qubits ceiling: dispatch() / submitCircuit() path
// -------------------------------------------------------------------------

it('rejects submitCircuit when the circuit exceeds max_qubits', function () use ($config) {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['max_qubits' => 5]));

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('qubitCount')->willReturn(6);
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(InvalidCircuitException::class);
});

it('allows submitCircuit when the circuit is within max_qubits', function () use ($config) {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['max_qubits' => 5]));

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('qubitCount')->willReturn(5);
    $circuit->method('toArray')->willReturn(['qubits' => 5, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toStartWith('local:');
});

// -------------------------------------------------------------------------
// Cost estimation: unsupported (the local simulator is free)
// -------------------------------------------------------------------------

it('does not implement EstimatesCost', function () {
    expect($this->driver)->not->toBeInstanceOf(EstimatesCost::class);
});

it('CircuitBuilder::estimateCost throws costEstimationUnsupported for the local driver', function () {
    $builder = new CircuitBuilder($this->driver, 'local');
    $builder->qubits(1)->h(0)->measure();

    expect(fn () => $builder->estimateCost())
        ->toThrow(QuantumExecutionException::class, 'local');
});
