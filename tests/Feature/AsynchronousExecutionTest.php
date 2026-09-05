<?php

declare(strict_types=1);

use Aether\Contracts\PythonExecutor;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Events\CircuitCompleted;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Facades\Quantum;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('dispatches a circuit as a queued submission job carrying the pinned driver', function () {
    Queue::fake();

    Quantum::circuit('local')
        ->qubits(2)
        ->h(0)
        ->cnot(0, 1)
        ->measure()
        ->shots(512)
        ->dispatch();

    Queue::assertPushed(SubmitQuantumCircuit::class, function (SubmitQuantumCircuit $job): bool {
        return $job->driver === 'local'
            && $job->circuit['qubits'] === 2
            && $job->circuit['shots'] === 512
            && count($job->circuit['gates']) === 3;
    });
});

it('refuses to dispatch on the local driver when the cache store cannot be shared', function () {
    Queue::fake();
    config([
        'cache.default' => 'array',
        'queue.default' => 'redis',
        'queue.connections.redis.driver' => 'redis',
    ]);

    expect(fn () => Quantum::circuit('local')->qubits(1)->h(0)->measure()->dispatch())
        ->toThrow(InvalidDriverConfigException::class, 'AETHER_LOCAL_CACHE_STORE');

    Queue::assertNothingPushed();
});

it('runs the whole asynchronous flow on the local driver and emits the result', function () {
    Event::fake([CircuitCompleted::class]);
    Bus::fake([PollQuantumTask::class]);

    // Everything below is the real thing — the two jobs, the real driver, its
    // task cache and the real event dispatcher. Only the Python subprocess is
    // stubbed, so the suite does not require a Braket installation.
    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn(['counts' => ['0' => 48, '1' => 52]]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    $circuit = Quantum::circuit('local')->qubits(1)->h(0)->measure()->shots(100);

    // Stage one: the submission job hands the task off to the backend.
    (new SubmitQuantumCircuit($circuit->toArray(), 'local'))->handle(app(QuantumManager::class));

    $arn = null;

    Bus::assertDispatched(PollQuantumTask::class, function (PollQuantumTask $job) use (&$arn): bool {
        $arn = $job->taskArn;

        return str_starts_with($job->taskArn, 'local:');
    });

    // Stage two: polling that task finds it completed and publishes the result.
    (new PollQuantumTask($arn, $circuit->toArray(), 'local'))->handle(
        app(QuantumManager::class),
        app(Dispatcher::class),
    );

    Event::assertDispatched(CircuitCompleted::class, function (CircuitCompleted $event) use ($arn): bool {
        return $event->driver === 'local'
            && $event->taskArn === $arn
            && $event->result instanceof CircuitResult
            && array_sum($event->result->counts()) === 100;
    });
});

it('lets the fake stand in for the whole dispatch cycle', function () {
    $fake = Quantum::fake();
    $fake->respondWithCounts(['00' => 700, '11' => 300]);

    $circuit = Quantum::circuit()->qubits(2)->h(0)->cnot(0, 1)->measure()->shots(1000);

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    $fake->assertCircuitDispatched(fn ($recorded) => $recorded->shotCount() === 1000);
    $fake->assertCircuitDispatchedTimes(1);
    $fake->assertCircuitNotRan();

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['00' => 700, '11' => 300]);
});
