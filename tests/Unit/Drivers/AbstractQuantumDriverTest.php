<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\AbstractQuantumDriver;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\BatchResult;
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

it('exposes assertConfigured() to subclasses for the asynchronous path', function () {
    // A driver whose async methods only need config validation, not the
    // synchronous-safety hook, must be able to call assertConfigured()
    // directly without going through the private preflight()/beforeExecution().
    $driver = new class($this->bridge, []) extends AbstractQuantumDriver
    {
        protected function driverName(): string
        {
            return 'test';
        }

        protected function requiredConfig(): array
        {
            return ['must_be_set'];
        }

        protected function beforeExecution(): void
        {
            throw new RuntimeException('beforeExecution must not run on the async path');
        }

        public function callAssertConfigured(): void
        {
            $this->assertConfigured();
        }
    };

    expect(fn () => $driver->callAssertConfigured())
        ->toThrow(InvalidDriverConfigException::class, 'must_be_set');
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

// -------------------------------------------------------------------------
// Malformed circuit.py responses
// -------------------------------------------------------------------------

it('throws when circuit.py response is missing the counts key', function () {
    $this->bridge->method('execute')->willReturn([]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->driver->executeCircuit($circuit);
})->throws(QuantumExecutionException::class, 'counts');

it('throws when circuit.py counts is not an array', function () {
    $this->bridge->method('execute')->willReturn(['counts' => 'not-an-array']);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->driver->executeCircuit($circuit);
})->throws(QuantumExecutionException::class, 'counts');

it('normalizes numeric-string count keys turned into ints by json_decode', function () {
    $this->bridge->method('execute')->willReturn(['counts' => [10 => 500, 11 => 500]]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 2, 'gates' => [], 'shots' => 1000]);

    $result = $this->driver->executeCircuit($circuit);

    expect($result->counts())->toBe(['10' => 500, '11' => 500]);
});

// -------------------------------------------------------------------------
// Malformed entropy.py responses
// -------------------------------------------------------------------------

it('throws when entropy.py response is missing the bits key', function () {
    $this->bridge->method('execute')->willReturn([]);

    $this->driver->generateEntropy(8);
})->throws(QuantumExecutionException::class, 'bits');

it('throws when entropy.py bits is not a string', function () {
    $this->bridge->method('execute')->willReturn(['bits' => 12345]);

    $this->driver->generateEntropy(8);
})->throws(QuantumExecutionException::class, 'bits');

it('throws when entropy.py returns fewer bits than requested', function () {
    $this->bridge->method('execute')->willReturn(['bits' => '1010']);

    $this->driver->generateEntropy(16);
})->throws(QuantumExecutionException::class);

// -------------------------------------------------------------------------
// entropy_qubits clamping
// -------------------------------------------------------------------------

it('clamps entropy_qubits to 16 when configured as zero', function () {
    $driver = new class($this->bridge, ['entropy_qubits' => 0]) extends AbstractQuantumDriver
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
            $this->callback(fn (array $p) => $p['qubits'] === 16 && $p['shots'] === 1),
            ['entropy_qubits' => 0]
        )
        ->willReturn(['bits' => str_repeat('1', 16)]);

    $bytes = $driver->generateEntropy(8);
    expect(strlen($bytes))->toBe(1);
});

it('clamps entropy_qubits to 16 when configured as negative', function () {
    $driver = new class($this->bridge, ['entropy_qubits' => -4]) extends AbstractQuantumDriver
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
            $this->callback(fn (array $p) => $p['qubits'] === 16),
            ['entropy_qubits' => -4]
        )
        ->willReturn(['bits' => str_repeat('1', 16)]);

    $driver->generateEntropy(8);
});

// -------------------------------------------------------------------------
// Malformed batch.py responses
// -------------------------------------------------------------------------

it('sends every circuit to batch.py and maps the results back in order', function () {
    $this->bridge->expects($this->once())
        ->method('execute')
        ->with(
            'batch.py',
            $this->callback(fn (array $p) => $p['driver'] === 'test'
                && $p['driver_config'] === ['key' => 'value']
                && $p['circuits'] === [
                    ['qubits' => 1, 'gates' => [], 'shots' => 1000],
                    ['qubits' => 2, 'gates' => [], 'shots' => 10],
                ]),
            ['key' => 'value']
        )
        ->willReturn(['results' => [
            ['counts' => ['0' => 500, '1' => 500]],
            ['counts' => ['00' => 10]],
        ]]);

    $first = $this->createMock(CircuitBuilder::class);
    $first->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);
    $second = $this->createMock(CircuitBuilder::class);
    $second->method('toArray')->willReturn(['qubits' => 2, 'gates' => [], 'shots' => 10]);

    $result = $this->driver->executeBatch([$first, $second]);

    expect($result)->toBeInstanceOf(BatchResult::class)
        ->and($result->get(0)->counts())->toBe(['0' => 500, '1' => 500])
        ->and($result->get(1)->counts())->toBe(['00' => 10]);
});

it('throws when batch.py omits the results key', function () {
    $this->bridge->method('execute')->willReturn(['counts' => []]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->driver->executeBatch([$circuit]);
})->throws(QuantumExecutionException::class, '"results" key');

it('throws when a batch.py result lacks a counts array', function () {
    $this->bridge->method('execute')->willReturn(['results' => [['status' => 'ok']]]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    $this->driver->executeBatch([$circuit]);
})->throws(QuantumExecutionException::class, '"counts" array');

it('throws when batch.py results count does not match circuits count', function () {
    $this->bridge->method('execute')->willReturn(['results' => [['counts' => ['0' => 500]]]]);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 1000]);

    // Pass 2 circuits, but mock returns 1 result
    $this->driver->executeBatch([$circuit, $circuit]);
})->throws(QuantumExecutionException::class, 'exactly 2 results, got 1');
