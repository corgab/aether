<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AwsBraketDriver;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;
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
        'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1',
        'bucket' => 'test-bucket',
        'synchronous_safe' => true,
        'pricing' => [
            'per_task' => 0.30,
            'per_shot' => 0.00035,
            'currency' => 'USD',
        ],
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

// -------------------------------------------------------------------------
// max_qubits ceiling: unaffected by default (matches config/aether.php,
// where the aws driver has no max_qubits configured)
// -------------------------------------------------------------------------

it('does not enforce a qubit ceiling on executeCircuit by default', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 30, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('does not enforce a qubit ceiling on submitCircuit by default', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 30, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['task_arn' => 'arn:aws:braket:us-east-1:123456789012:quantum-task/big']);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toBe('arn:aws:braket:us-east-1:123456789012:quantum-task/big');
});

it('enforces a configured max_qubits on submitCircuit as well as executeCircuit', function () {
    $driver = new AwsBraketDriver($this->bridge, array_merge($this->config, ['max_qubits' => 10]));

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('qubitCount')->willReturn(20);
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(InvalidCircuitException::class, 'max_qubits');
});

it('throws QuantumExecutionException when check.py status is unknown', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn(['status' => 'BOGUS']);

    expect(fn () => $driver->checkTask('arn:...'))
        ->toThrow(QuantumExecutionException::class, 'status');
});

// -------------------------------------------------------------------------
// estimateCost()
// -------------------------------------------------------------------------

it('implements EstimatesCost interface', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    expect($driver)->toBeInstanceOf(EstimatesCost::class);
});

it('estimates cost from configured pricing for a single task', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $estimate = $driver->estimateCost(1000);

    expect($estimate)->toBeInstanceOf(CostEstimate::class);
    expect(round($estimate->amount, 10))->toBe(0.65);
    expect($estimate->currency)->toBe('USD');
    expect($estimate->shots)->toBe(1000);
    expect($estimate->breakdown)->toBe(['per_task' => 0.30, 'per_shot' => 0.35]);
});

it('scales the per_task component by the given task count', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $estimate = $driver->estimateCost(2000, tasks: 2);

    expect($estimate->breakdown['per_task'])->toBe(0.60);
    expect($estimate->breakdown['per_shot'])->toBe(0.70);
    expect(round($estimate->amount, 10))->toBe(1.30);
});

it('falls back to zero-cost rates when pricing config is absent', function () {
    $driver = new AwsBraketDriver($this->bridge, [
        'region' => 'us-east-1',
        'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1',
        'bucket' => 'test-bucket',
    ]);

    $estimate = $driver->estimateCost(1000);

    expect($estimate->amount)->toBe(0.0);
    expect($estimate->currency)->toBe('USD');
});

// -------------------------------------------------------------------------
// max_cost_per_run guard
// -------------------------------------------------------------------------

it('does not enforce a cost ceiling on executeCircuit by default', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1_000_000]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 1]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('fails fast when max_cost_per_run is set without pricing rates', function () {
    // Without rates every estimate is 0.00 and the ceiling would never trip
    // while looking configured — a misconfiguration, not an unlimited budget.
    $config = array_merge($this->config, ['max_cost_per_run' => 1.0, 'pricing' => []]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(10);

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->executeCircuit($circuit))
        ->toThrow(InvalidDriverConfigException::class, 'pricing.per_task, pricing.per_shot');
});

it('treats an empty-string max_cost_per_run (a blank env var) as no ceiling', function () {
    // env('AETHER_AWS_MAX_COST') yields '' for `AETHER_AWS_MAX_COST=` in .env,
    // which must not collapse into a ceiling of (float) '' === 0.0.
    $driver = new AwsBraketDriver($this->bridge, array_merge($this->config, ['max_cost_per_run' => '']));

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(1_000_000);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1_000_000]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 1]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('throws InvalidCircuitException on executeCircuit when the estimated cost exceeds max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 0.5]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(1000); // 0.30 + 0.35 = 0.65 > 0.5
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->executeCircuit($circuit))
        ->toThrow(InvalidCircuitException::class);
});

it('allows executeCircuit when the estimated cost is exactly at the max_cost_per_run boundary', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 0.65]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(1000); // exactly 0.65
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 1000]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('throws InvalidCircuitException on submitCircuit when the estimated cost exceeds max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 0.5]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(1000);
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(InvalidCircuitException::class);
});

it('allows submitCircuit when the estimated cost is within max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 1.0]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('shotCount')->willReturn(1000);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->bridge->method('execute')->willReturn(['task_arn' => 'arn:aws:braket:us-east-1:123456789012:quantum-task/ok']);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toBe('arn:aws:braket:us-east-1:123456789012:quantum-task/ok');
});

it('throws InvalidCircuitException on executeBatch when the total estimated cost exceeds max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 1.0]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $first = $this->createMock(CircuitBuilder::class);
    $first->method('shotCount')->willReturn(1000);
    $second = $this->createMock(CircuitBuilder::class);
    $second->method('shotCount')->willReturn(1000);
    // 2 tasks * 0.30 + 2000 shots * 0.00035 = 0.60 + 0.70 = 1.30 > 1.0

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->executeBatch([$first, $second]))
        ->toThrow(InvalidCircuitException::class);
});

it('allows executeBatch when the total estimated cost is within max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 2.0]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $first = $this->createMock(CircuitBuilder::class);
    $first->method('shotCount')->willReturn(1000);
    $first->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);
    $second = $this->createMock(CircuitBuilder::class);
    $second->method('shotCount')->willReturn(1000);
    $second->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->bridge->method('execute')->willReturn([
        'results' => [
            ['counts' => ['0' => 1000]],
            ['counts' => ['0' => 1000]],
        ],
    ]);

    $result = $driver->executeBatch([$first, $second]);

    expect($result->count())->toBe(2);
});

it('does not enforce a cost ceiling when max_cost_per_run is null', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => null]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1_000_000]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 1]]);

    $result = $driver->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('does not enforce a cost ceiling on generateEntropy by default', function () {
    $driver = new AwsBraketDriver($this->bridge, $this->config);

    $this->bridge->method('execute')->willReturn(['bits' => str_repeat('1', 256)]);

    $bytes = $driver->generateEntropy(256);

    expect(strlen($bytes))->toBe(32);
});

it('throws InvalidCircuitException on generateEntropy when the estimated cost exceeds max_cost_per_run', function () {
    // Default entropy_qubits (16) -> 16 shots for 256 bits: 0.30 + 16 * 0.00035 = 0.3056 > 0.25
    $config = array_merge($this->config, ['max_cost_per_run' => 0.25]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $this->bridge->expects($this->never())->method('execute');

    try {
        $driver->generateEntropy(256);
        $this->fail('Expected InvalidCircuitException was not thrown.');
    } catch (InvalidCircuitException $e) {
        expect($e->getMessage())->toContain('max_cost_per_run');
    }
});

it('allows generateEntropy when the estimated cost is within max_cost_per_run', function () {
    $config = array_merge($this->config, ['max_cost_per_run' => 0.50]);
    $driver = new AwsBraketDriver($this->bridge, $config);

    $this->bridge->method('execute')->willReturn(['bits' => str_repeat('1', 256)]);

    $bytes = $driver->generateEntropy(256);

    expect(strlen($bytes))->toBe(32);
});
