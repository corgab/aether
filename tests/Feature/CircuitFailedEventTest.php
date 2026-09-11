<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Events\CircuitCompleted;
use Aether\Events\CircuitFailed;
use Aether\Exceptions\TaskFailedException;
use Aether\Facades\Quantum;
use Aether\Jobs\PollQuantumTask;
use Aether\QuantumManager;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

it('dispatches CircuitFailed exactly once when the polling job runs against a stubbed failure', function (TaskStatus $status) {
    Event::fake();

    $fake = Quantum::fake()->respondWithTaskStatus($status);
    $circuit = (new CircuitBuilder($fake, 'aws'))->qubits(1)->h(0)->measure();
    $arn = $fake->submitCircuit($circuit);

    $job = new PollQuantumTask($arn, $circuit->toArray(), 'aws');

    expect(fn () => $job->handle(app(QuantumManager::class), app(Dispatcher::class), app(QuantumTaskRecorder::class)))
        ->toThrow(TaskFailedException::class);

    Event::assertDispatchedTimes(CircuitFailed::class, 1);
    Event::assertDispatched(
        CircuitFailed::class,
        fn (CircuitFailed $event): bool => $event->driver === 'aws'
            && $event->taskArn === $arn
            && $event->status === $status
            && $event->circuit === $circuit->toArray(),
    );
    Event::assertNotDispatched(CircuitCompleted::class);
})->with([TaskStatus::Failed, TaskStatus::Cancelled]);

it('does not make the fake itself dispatch CircuitFailed when polled', function () {
    Event::fake();

    $fake = Quantum::fake()->respondWithTaskStatus(TaskStatus::Failed);
    $arn = $fake->submitCircuit((new CircuitBuilder($fake))->qubits(1)->measure());

    $fake->checkTask($arn);

    Event::assertNotDispatched(CircuitFailed::class);
});

it('still fails the job with the task exception when a CircuitFailed listener throws', function () {
    $fake = Quantum::fake()->respondWithTaskStatus(TaskStatus::Failed);
    $circuit = (new CircuitBuilder($fake, 'aws'))->qubits(1)->h(0)->measure();
    $arn = $fake->submitCircuit($circuit);

    Event::listen(CircuitFailed::class, function (): void {
        throw new RuntimeException('notification provider is down');
    });

    $job = new PollQuantumTask($arn, $circuit->toArray(), 'aws');

    expect(fn () => $job->handle(app(QuantumManager::class), app(Dispatcher::class), app(QuantumTaskRecorder::class)))
        ->toThrow(TaskFailedException::class, $arn);
});
