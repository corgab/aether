<?php

declare(strict_types=1);

use Aether\Contracts\PythonExecutor;
use Aether\Drivers\LocalSimulatorDriver;
use Aether\Events\CircuitExecuted;
use Aether\Events\EntropyGenerated;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Facades\Quantum;
use Aether\Results\CircuitResult;
use Illuminate\Support\Facades\Event;

// -------------------------------------------------------------------------
// Real driver — successful synchronous execution
// -------------------------------------------------------------------------

it('dispatches CircuitExecuted when a circuit executes synchronously', function () {
    Event::fake([CircuitExecuted::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn(['counts' => ['0' => 48, '1' => 52]]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    $circuit = Quantum::circuit('local')->qubits(1)->h(0)->measure()->shots(100);
    $definition = $circuit->toArray();

    $result = $circuit->run();

    Event::assertDispatched(CircuitExecuted::class, function (CircuitExecuted $event) use ($definition, $result): bool {
        return $event->driver === 'local'
            && $event->circuit === $definition
            && $event->result instanceof CircuitResult
            && $event->result->counts() === $result->counts();
    });
});

it('does not dispatch CircuitExecuted when the circuit fails to execute', function () {
    Event::fake([CircuitExecuted::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn([]); // missing the "counts" key

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    expect(fn () => Quantum::circuit('local')->qubits(1)->h(0)->measure()->run())
        ->toThrow(QuantumExecutionException::class);

    Event::assertNotDispatched(CircuitExecuted::class);
});

it('does not dispatch CircuitExecuted when a circuit is dispatched asynchronously on the local driver', function () {
    Event::fake([CircuitExecuted::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn(['counts' => ['0' => 48, '1' => 52]]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    // The local driver simulates submission by running the circuit inline; to
    // the caller that is still an asynchronous dispatch, announced only by
    // CircuitCompleted from the polling job — never by the synchronous event.
    $taskArn = Quantum::driver('local')->submitCircuit(
        Quantum::circuit('local')->qubits(1)->h(0)->measure()->shots(100)
    );

    expect($taskArn)->toStartWith('local:');
    Event::assertNotDispatched(CircuitExecuted::class);
});

// -------------------------------------------------------------------------
// Real driver — successful entropy generation
// -------------------------------------------------------------------------

it('dispatches EntropyGenerated when entropy is generated', function () {
    Event::fake([EntropyGenerated::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('bitstringToBytes')->willReturnCallback(
        fn (string $bits): string => chr((int) bindec($bits))
    );
    $bridge->method('execute')->willReturn(['bits' => str_repeat('1', 16)]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    Quantum::entropy('local')->generate(8);

    Event::assertDispatched(
        EntropyGenerated::class,
        fn (EntropyGenerated $event): bool => $event->driver === 'local' && $event->bits === 8
    );
});

it('does not dispatch EntropyGenerated when entropy generation fails', function () {
    Event::fake([EntropyGenerated::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn([]); // missing the "bits" key

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    expect(fn () => Quantum::entropy('local')->generate(8))
        ->toThrow(QuantumExecutionException::class);

    Event::assertNotDispatched(EntropyGenerated::class);
});

// -------------------------------------------------------------------------
// Quantum::fake() — event parity with the real drivers
// -------------------------------------------------------------------------

it('dispatches CircuitExecuted through Quantum::fake() for Event::fake() parity', function () {
    Event::fake([CircuitExecuted::class]);
    $fake = Quantum::fake();

    Quantum::circuit('local')->qubits(2)->h(0)->cnot(0, 1)->measure()->shots(1000)->run();

    $fake->assertCircuitRan();
    Event::assertDispatched(
        CircuitExecuted::class,
        fn (CircuitExecuted $event): bool => $event->driver === 'local' && $event->circuit['shots'] === 1000
    );
});

it('dispatches EntropyGenerated through Quantum::fake() for Event::fake() parity', function () {
    Event::fake([EntropyGenerated::class]);
    $fake = Quantum::fake();

    Quantum::entropy()->generate(128);

    $fake->assertEntropyGenerated(128);
    Event::assertDispatched(EntropyGenerated::class, fn (EntropyGenerated $event): bool => $event->bits === 128);
});

it('reports the resolved driver alias on EntropyGenerated through Quantum::fake()', function () {
    Event::fake([EntropyGenerated::class]);
    Quantum::fake();

    Quantum::entropy('aws')->generate(64);

    Event::assertDispatched(EntropyGenerated::class, fn (EntropyGenerated $event): bool => $event->driver === 'aws');
});

// -------------------------------------------------------------------------
// Batch execution — one CircuitExecuted per circuit, real driver and fake
// -------------------------------------------------------------------------

it('dispatches one CircuitExecuted per circuit when a batch executes', function () {
    Event::fake([CircuitExecuted::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn(['results' => [
        ['counts' => ['0' => 100]],
        ['counts' => ['1' => 100]],
    ]]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    $first = Quantum::circuit('local')->qubits(1)->h(0)->measure()->shots(100);
    $second = Quantum::circuit('local')->qubits(1)->x(0)->measure()->shots(100);

    Quantum::batch([$first, $second])->run();

    Event::assertDispatchedTimes(CircuitExecuted::class, 2);
    Event::assertDispatched(
        CircuitExecuted::class,
        fn (CircuitExecuted $event): bool => $event->circuit === $second->toArray() && $event->result->counts() === ['1' => 100]
    );
});

it('does not dispatch CircuitExecuted when a batch response is malformed', function () {
    Event::fake([CircuitExecuted::class]);

    $bridge = $this->createMock(PythonExecutor::class);
    $bridge->method('execute')->willReturn(['results' => [['counts' => ['0' => 100]]]]);

    Quantum::extend('local', fn (): LocalSimulatorDriver => new LocalSimulatorDriver($bridge, []));
    Quantum::forgetDrivers();

    $first = Quantum::circuit('local')->qubits(1)->h(0)->measure();
    $second = Quantum::circuit('local')->qubits(1)->x(0)->measure();

    expect(fn () => Quantum::batch([$first, $second])->run())->toThrow(QuantumExecutionException::class);
    Event::assertNotDispatched(CircuitExecuted::class);
});

it('dispatches one CircuitExecuted per circuit through Quantum::fake() batches', function () {
    Event::fake([CircuitExecuted::class]);
    Quantum::fake();

    $first = Quantum::circuit('local')->qubits(1)->h(0)->measure();
    $second = Quantum::circuit('local')->qubits(1)->x(0)->measure();

    Quantum::batch([$first, $second])->run();

    Event::assertDispatchedTimes(CircuitExecuted::class, 2);
});

// -------------------------------------------------------------------------
// Quantum::fake($stub) — stubbing changes what is returned, not the eventing
// -------------------------------------------------------------------------

it('dispatches CircuitExecuted carrying the stubbed CircuitResult', function () {
    Event::fake([CircuitExecuted::class]);
    $fake = Quantum::fake(['00' => 700, '11' => 324]);

    $result = Quantum::circuit('local')->qubits(2)->h(0)->cnot(0, 1)->measure()->run();

    expect($result->counts())->toBe(['00' => 700, '11' => 324]);
    $fake->assertCircuitRan();
    Event::assertDispatched(
        CircuitExecuted::class,
        fn (CircuitExecuted $event): bool => $event->result->counts() === ['00' => 700, '11' => 324]
    );
});

it('dispatches EntropyGenerated carrying the requested bit count when entropy is stubbed', function () {
    Event::fake([EntropyGenerated::class]);
    $fake = Quantum::fake()->respondEntropyWith("\xFF");

    $entropy = Quantum::entropy()->generate(8);

    expect($entropy)->toBe("\xFF");
    $fake->assertEntropyGenerated(8);
    Event::assertDispatched(EntropyGenerated::class, fn (EntropyGenerated $event): bool => $event->bits === 8);
});
