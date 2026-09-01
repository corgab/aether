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
