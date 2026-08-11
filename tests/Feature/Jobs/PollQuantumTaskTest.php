<?php

declare(strict_types=1);

use Aether\Events\CircuitCompleted;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\Jobs\PollQuantumTask;
use Aether\QuantumManager;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('re-queues itself with attempt + 1 and the configured delay when the task is not terminal', function () {
    Queue::fake();
    config(['aether.poll_interval' => 3, 'aether.max_poll_attempts' => 720]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async', 4);
    $job->handle($manager, app(Dispatcher::class));

    Queue::assertPushed(
        PollQuantumTask::class,
        fn (PollQuantumTask $polled): bool => $polled->taskArn === $device->taskArnToReturn
            && $polled->driver === 'fake-async'
            && $polled->attempt === 5
            && $polled->delay === 3,
    );
});

it('throws pollingExhausted and does not re-queue once past max_poll_attempts', function () {
    Queue::fake();
    config(['aether.max_poll_attempts' => 10]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async', 10);

    try {
        $job->handle($manager, app(Dispatcher::class));
        $this->fail('Expected QuantumExecutionException to be thrown.');
    } catch (QuantumExecutionException $exception) {
        expect($exception->getMessage())->toContain($device->taskArnToReturn);
    }

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('throws TaskFailedException when the task terminates as failed or cancelled', function (TaskStatus $status) {
    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot($status);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    $job->handle($manager, app(Dispatcher::class));
})->with([TaskStatus::Failed, TaskStatus::Cancelled])->throws(TaskFailedException::class);

it('dispatches CircuitCompleted with the counts and task arn once completed', function () {
    Event::fake();

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, ['00' => 3, '11' => 7]);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $circuit = ['qubits' => 2, 'gates' => [], 'shots' => 10];
    $job = new PollQuantumTask($device->taskArnToReturn, $circuit, 'fake-async');
    $job->handle($manager, app(Dispatcher::class));

    Event::assertDispatched(
        CircuitCompleted::class,
        fn (CircuitCompleted $event): bool => $event->driver === 'fake-async'
            && $event->taskArn === $device->taskArnToReturn
            && $event->circuit === $circuit
            && $event->result->counts() === ['00' => 3, '11' => 7],
    );
});

it('resolves the default driver name when none is given explicitly', function () {
    Event::fake();
    config(['aether.default' => 'fake-async']);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, ['0' => 1]);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 1, 'gates' => [], 'shots' => 1]);
    $job->handle($manager, app(Dispatcher::class));

    Event::assertDispatched(
        CircuitCompleted::class,
        fn (CircuitCompleted $event): bool => $event->driver === 'fake-async',
    );
});

it('throws a malformed response exception when completed with null counts', function () {
    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, null);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    $job->handle($manager, app(Dispatcher::class));
})->throws(QuantumExecutionException::class);

it('throws asynchronousUnsupported when the resolved driver does not support async execution', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $job = new PollQuantumTask('arn:fake', ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->handle($manager, app(Dispatcher::class));
})->throws(QuantumExecutionException::class);
