<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Events\CircuitFailed;
use Aether\Facades\Quantum;
use Aether\Tasks\TaskStatus;
use Illuminate\Support\Facades\Event;

it('makes the fake dispatch CircuitFailed once when a stubbed task ends as failed or cancelled', function (TaskStatus $status) {
    Event::fake();

    $fake = Quantum::fake()->respondWithTaskStatus($status);
    $circuit = (new CircuitBuilder($fake, 'aws'))->qubits(1)->h(0)->measure();

    $arn = $fake->submitCircuit($circuit);
    $fake->checkTask($arn);
    $fake->checkTask($arn);

    Event::assertDispatchedTimes(CircuitFailed::class, 1);
    Event::assertDispatched(
        CircuitFailed::class,
        fn (CircuitFailed $event): bool => $event->driver === 'aws'
            && $event->taskArn === $arn
            && $event->status === $status
            && $event->circuit === $circuit->toArray()
            && str_contains($event->reason, $status->value),
    );
})->with([TaskStatus::Failed, TaskStatus::Cancelled]);

it('does not make the fake dispatch CircuitFailed for a task that is still in flight', function () {
    Event::fake();

    $fake = Quantum::fake()->respondWithTaskStatus(TaskStatus::Running);
    $arn = $fake->submitCircuit((new CircuitBuilder($fake))->qubits(1)->measure());

    $fake->checkTask($arn);

    Event::assertNotDispatched(CircuitFailed::class);
});

it('does not make the fake dispatch CircuitFailed for a completed task', function () {
    Event::fake();

    $fake = Quantum::fake();
    $arn = $fake->submitCircuit((new CircuitBuilder($fake))->qubits(1)->measure());

    $fake->checkTask($arn);

    Event::assertNotDispatched(CircuitFailed::class);
});
