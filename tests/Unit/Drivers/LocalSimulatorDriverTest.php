<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\ValidatesDispatch;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheContract;
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
    // The driver takes its cache store by injection, so an in-memory
    // repository is all submitCircuit()/checkTask() need: no facade root, no
    // container, no global config() helper.
    $this->cache = new CacheRepository(new ArrayStore);
    $this->driver = new LocalSimulatorDriver($this->bridge, $this->config, $this->cache);

    $container = tap(new Container, function (Container $container) {
        $container->instance('config', new ConfigRepository([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                    'shared' => ['driver' => 'array'],
                ],
            ],
        ]));
    });

    Container::setInstance($container);
});

afterEach(function () {
    Container::setInstance(null);
});

// -------------------------------------------------------------------------
// Contract
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// executeCircuit()
// -------------------------------------------------------------------------

it('refuses executeCircuit when synchronous_safe is false', function () {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($this->config, ['synchronous_safe' => false]), $this->cache);
    $circuit = (new CircuitBuilder($driver))->qubits(1)->h(0)->measure();

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->executeCircuit($circuit))
        ->toThrow(QuantumExecutionException::class, 'synchronous_safe => false');
});

it('still simulates an asynchronous submitCircuit when synchronous_safe is false', function () {
    // The local driver implements ->dispatch() by running the circuit inline.
    // That is the asynchronous path the flag steers users toward, so it must
    // never be refused, whatever synchronous_safe says.
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($this->config, ['synchronous_safe' => false]), $this->cache);
    $circuit = (new CircuitBuilder($driver))->qubits(1)->h(0)->measure();

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 500, '1' => 500]]);

    $taskArn = $driver->submitCircuit($circuit);

    expect($taskArn)->toStartWith('local:')
        ->and($driver->checkTask($taskArn)->status)->toBe(TaskStatus::Completed);
});

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

// -------------------------------------------------------------------------
// AsynchronousDevice: simulated local async
// -------------------------------------------------------------------------

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

it('caches a dispatched result for the configured task_ttl', function (mixed $ttl, int $expected) {
    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $cache = $this->createMock(CacheContract::class);
    $cache->expects($this->once())
        ->method('put')
        ->with($this->stringStartsWith('aether:local-task:local:'), ['0' => 100], $expected)
        ->willReturn(true);

    $driver = new LocalSimulatorDriver($this->bridge, array_merge($this->config, ['task_ttl' => $ttl]), $cache);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);
    $circuit->method('qubitCount')->willReturn(1);

    $driver->submitCircuit($circuit);
})->with([
    'configured' => [42, 42],
    'numeric string from env' => ['90', 90],
    'absent' => [null, LocalSimulatorDriver::DEFAULT_TASK_TTL],
    'zero' => [0, LocalSimulatorDriver::DEFAULT_TASK_TTL],
    'garbage' => ['soon', LocalSimulatorDriver::DEFAULT_TASK_TTL],
]);

it('checkTask reports Failed for an unknown task key', function () {
    $snapshot = $this->driver->checkTask('local:'.Str::uuid());

    expect($snapshot)->toBeInstanceOf(TaskSnapshot::class);
    expect($snapshot->status)->toBe(TaskStatus::Failed);
    expect($snapshot->counts)->toBeNull();
});

it('checkTask rejects a task arn that is not in local: form', function () {
    expect(fn () => $this->driver->checkTask('arn:aws:braket:us-east-1:123456789012:quantum-task/abc'))
        ->toThrow(QuantumExecutionException::class);
});

// -------------------------------------------------------------------------
// cache_store: which cache store asynchronous results are kept in
// -------------------------------------------------------------------------

it('stores asynchronous results in the configured cache store', function () use ($config) {
    $sharedCache = new CacheRepository(new ArrayStore);
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['cache_store' => 'shared']), $sharedCache);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 75, '1' => 25]]);

    $taskArn = $driver->submitCircuit($circuit);
    $key = 'aether:local-task:'.$taskArn;

    expect($sharedCache->get($key))->toBe(['0' => 75, '1' => 25]);
    expect($this->cache->get($key))->toBeNull();

    $snapshot = $driver->checkTask($taskArn);

    expect($snapshot->status)->toBe(TaskStatus::Completed);
    expect($snapshot->counts)->toBe(['0' => 75, '1' => 25]);
});

it('falls back to the default cache store when cache_store is not set', function (mixed $cacheStore) use ($config) {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['cache_store' => $cacheStore]), $this->cache);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $taskArn = $driver->submitCircuit($circuit);
    $key = 'aether:local-task:'.$taskArn;

    expect($this->cache->get($key))->toBe(['0' => 100]);
})->with([
    'null' => [null],
    'blank string' => [''],
    'whitespace' => ['  '],
]);

it('refuses the array store when the submission job runs on a connection that crosses processes', function () {
    Container::getInstance()->make('config')->set('queue.connections.redis.driver', 'redis');

    $exception = null;

    try {
        $this->driver->validateDispatch('redis');
    } catch (InvalidDriverConfigException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(InvalidDriverConfigException::class);
    expect($exception->getMessage())
        ->toContain('AETHER_LOCAL_CACHE_STORE')
        ->toContain('redis');
});

it('allows the array store when the submission job runs on the sync connection', function () {
    Container::getInstance()->make('config')->set('queue.connections.sync.driver', 'sync');

    $this->driver->validateDispatch('sync');

    expect(true)->toBeTrue();
});

it('allows the array store when the connection is unknown or cannot be resolved', function (?string $connection) {
    $this->driver->validateDispatch($connection);

    expect(true)->toBeTrue();
})->with(['dispatch time' => [null], 'undefined connection' => ['ghost']]);

it('does not consult the queue when submitCircuit is called directly', function () {
    Container::getInstance()->make('config')->set('queue.connections.redis.driver', 'redis');

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    expect($this->driver->submitCircuit($circuit))->toStartWith('local:');
});

it('trusts an explicitly configured array store', function () use ($config) {
    Container::getInstance()->make('config')->set('queue.connections.redis.driver', 'redis');

    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['cache_store' => 'array']), $this->cache);
    $driver->validateDispatch('redis');

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);

    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $taskArn = $driver->submitCircuit($circuit);

    expect($this->cache->get('aether:local-task:'.$taskArn))->toBe(['0' => 100]);
});

it('trims surrounding whitespace from cache_store', function () use ($config) {
    $sharedCache = new CacheRepository(new ArrayStore);
    $driver = new LocalSimulatorDriver($this->bridge, [...$config, 'cache_store' => ' shared '], $sharedCache);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('toArray')->willReturn(['qubits' => 1, 'gates' => [], 'shots' => 100]);
    $this->bridge->method('execute')->willReturn(['counts' => ['0' => 100]]);

    $taskArn = $driver->submitCircuit($circuit);

    expect($sharedCache->get('aether:local-task:'.$taskArn))->toBe(['0' => 100]);
});

it('refuses the null store whether it is the default or named explicitly', function (?string $cacheStore, string $named) use ($config) {
    $repository = Container::getInstance()->make('config');
    $repository->set('cache.default', $cacheStore === null ? 'void' : 'array');
    $driver = new LocalSimulatorDriver($this->bridge, [...$config, 'cache_store' => $cacheStore], new CacheRepository(new NullStore));
    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit(
        (new CircuitBuilder($driver, 'local'))->qubits(1)->h(0)->measure()->shots(10)
    ))->toThrow(InvalidDriverConfigException::class, "cache store [{$named}]");
})->with([
    'default store' => [null, 'void'],
    'explicit store' => ['void', 'void'],
    "Laravel's implicit null store" => ['null', 'null'],
]);

it('rejects a discarding default store already at dispatch time', function () use ($config) {
    $repository = Container::getInstance()->make('config');
    $repository->set('cache.default', 'void');

    $driver = new LocalSimulatorDriver($this->bridge, $config, new CacheRepository(new NullStore));

    expect($driver)->toBeInstanceOf(ValidatesDispatch::class)
        ->and(fn () => $driver->validateDispatch())
        ->toThrow(InvalidDriverConfigException::class, 'cache store [void]');
});

// -------------------------------------------------------------------------
// max_qubits ceiling: dispatch() / submitCircuit() path
// -------------------------------------------------------------------------

it('rejects submitCircuit when the circuit exceeds max_qubits', function () use ($config) {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['max_qubits' => 5]), $this->cache);

    $circuit = $this->createMock(CircuitBuilder::class);
    $circuit->method('qubitCount')->willReturn(6);
    $circuit->expects($this->never())->method('toArray');

    $this->bridge->expects($this->never())->method('execute');

    expect(fn () => $driver->submitCircuit($circuit))
        ->toThrow(InvalidCircuitException::class);
});

it('allows submitCircuit when the circuit is within max_qubits', function () use ($config) {
    $driver = new LocalSimulatorDriver($this->bridge, array_merge($config, ['max_qubits' => 5]), $this->cache);

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
