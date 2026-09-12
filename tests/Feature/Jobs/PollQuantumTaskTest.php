<?php

declare(strict_types=1);

use Aether\Config\AetherConfig;
use Aether\Events\CircuitCompleted;
use Aether\Events\CircuitFailed;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\Jobs\PollQuantumTask;
use Aether\QuantumManager;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

it('budgets transient exceptions from the configured max_poll_exceptions', function () {
    $job = new PollQuantumTask('arn:fake', ['qubits' => 1, 'gates' => [], 'shots' => 1]);

    expect($job->maxExceptions)->toBe(5);

    config(['aether.max_poll_exceptions' => 2]);

    $job = new PollQuantumTask('arn:fake', ['qubits' => 1, 'gates' => [], 'shots' => 1]);

    expect($job->maxExceptions)->toBe(2);
});

it('backs off by the poll interval after a transient exception', function () {
    config(['aether.poll_interval' => 9]);

    $job = new PollQuantumTask('arn:fake', ['qubits' => 1, 'gates' => [], 'shots' => 1]);

    expect($job->backoff())->toBe(9);
});

it('budgets its attempts from the configured max_poll_attempts', function () {
    config(['aether.max_poll_attempts' => 42]);

    $job = new PollQuantumTask('arn:fake', ['qubits' => 1, 'gates' => [], 'shots' => 1]);

    expect($job->tries())->toBe(42);
});

it('budgets the attempts inside handle() from the injected settings, not the container', function () {
    config(['aether.max_poll_attempts' => 720]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $mockJob = Mockery::mock(Job::class);
    $mockJob->shouldReceive('attempts')->andReturn(2);
    $mockJob->shouldNotReceive('release');
    $mockJob->shouldReceive('fail')->once()->with(Mockery::on(
        fn (QuantumExecutionException $exception): bool => str_contains($exception->getMessage(), $device->taskArnToReturn)
    ));

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($mockJob);

    $settings = new AetherConfig(new Repository(['aether' => ['max_poll_attempts' => 2]]));

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), $settings);
});

it('releases itself back to the queue with the configured delay while the task is not terminal', function (TaskStatus $status) {
    config(['aether.poll_interval' => 3, 'aether.max_poll_attempts' => 720]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot($status);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))->withFakeQueueInteractions();
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertReleased(delay: 3);
})->with([TaskStatus::Created, TaskStatus::Queued, TaskStatus::Running, TaskStatus::Cancelling]);

it('fails without retry and does not release once past max_poll_attempts', function () {
    config(['aether.max_poll_attempts' => 2]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    // A real queue Job double lets us drive attempts() past the configured budget.
    $mockJob = Mockery::mock(Job::class);
    $mockJob->shouldReceive('attempts')->andReturn(2);
    $mockJob->shouldNotReceive('release');
    $mockJob->shouldReceive('fail')->once()->with(Mockery::on(
        fn (QuantumExecutionException $exception): bool => str_contains($exception->getMessage(), $device->taskArnToReturn)
    ));

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($mockJob);

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));
});

it('throws TaskFailedException when the task terminates as failed or cancelled', function (TaskStatus $status) {
    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot($status);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));
})->with([TaskStatus::Failed, TaskStatus::Cancelled])->throws(TaskFailedException::class);

it('dispatches CircuitFailed before throwing when the task terminates as failed or cancelled', function (TaskStatus $status) {
    Event::fake();

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot($status);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $circuit = ['qubits' => 2, 'gates' => [], 'shots' => 100];
    $job = new PollQuantumTask($device->taskArnToReturn, $circuit, 'fake-async');

    expect(fn () => $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class)))->toThrow(TaskFailedException::class);

    Event::assertDispatched(
        CircuitFailed::class,
        fn (CircuitFailed $event): bool => $event->driver === 'fake-async'
            && $event->taskArn === $device->taskArnToReturn
            && $event->circuit === $circuit
            && $event->status === $status
            && str_contains($event->reason, $status->value),
    );
    Event::assertNotDispatched(CircuitCompleted::class);
})->with([TaskStatus::Failed, TaskStatus::Cancelled]);

it('lets a transient checkTask failure propagate so the worker retries it', function () {
    $device = new FakeAsynchronousDevice;
    $device->throwOnCheck = QuantumExecutionException::fromPythonError('check.py', 'boom', 1);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    expect(fn () => $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow($device->throwOnCheck);

    $job->assertNotFailed();
    $job->assertNotReleased();
    $job->assertNotDeleted();
});

it('fails without retry on a driver configuration error', function () {
    $device = new FakeAsynchronousDevice;
    $device->throwOnCheck = InvalidDriverConfigException::missingKeys('aws', ['bucket']);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith(InvalidDriverConfigException::class);
    $job->assertNotReleased();
});

it('fails without retry when the driver cannot be resolved', function () {
    $job = (new PollQuantumTask('arn:fake', ['qubits' => 1, 'gates' => [], 'shots' => 1], 'not-a-driver'))
        ->withFakeQueueInteractions();

    $job->handle(app(QuantumManager::class), app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith(DriverNotFoundException::class);
    $job->assertNotReleased();
});

it('fails without retry on a deterministic driver error', function (Throwable $error) {
    $device = new FakeAsynchronousDevice;
    $device->throwOnCheck = $error;

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith($error::class);
    $job->assertNotReleased();
})->with([
    'unreadable check.py response' => [QuantumExecutionException::malformedResponse('check.py', 'no status')],
]);

it('dispatches CircuitFailed with the last known status when the polling budget is exhausted', function () {
    Event::fake();
    config(['aether.max_poll_attempts' => 2]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $mockJob = Mockery::mock(Job::class);
    $mockJob->shouldReceive('attempts')->andReturn(2);
    $mockJob->shouldReceive('fail')->once();

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($mockJob);

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    Event::assertDispatched(
        CircuitFailed::class,
        fn (CircuitFailed $event): bool => $event->status === TaskStatus::Running
            && $event->taskArn === $device->taskArnToReturn
            && str_contains($event->reason, $device->taskArnToReturn),
    );
});

it('releases with the same delay it reports as backoff', function () {
    config(['aether.poll_interval' => 4]);

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($job->backoff())->toBe(4);
    $job->assertReleased(delay: 4);
});

it('fails without retry when the task terminates as failed or cancelled', function (TaskStatus $status) {
    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot($status);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith(TaskFailedException::class);
})->with([TaskStatus::Failed, TaskStatus::Cancelled]);

it('reports the exception it fails with', function () {
    Exceptions::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnCheck = InvalidDriverConfigException::missingKeys('aws', ['bucket']);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    Exceptions::assertReported(InvalidDriverConfigException::class);
});

it('dispatches CircuitFailed when the task completes without counts', function () {
    Event::fake();

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, null);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class)))->toThrow(QuantumExecutionException::class);

    Event::assertDispatched(
        CircuitFailed::class,
        fn (CircuitFailed $event): bool => $event->status === TaskStatus::Completed
            && str_contains($event->reason, 'no measurement counts'),
    );
    Event::assertNotDispatched(CircuitCompleted::class);
});

it('does not dispatch CircuitFailed when the task completes with counts', function () {
    Event::fake();

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, ['0' => 1]);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new PollQuantumTask($device->taskArnToReturn, ['qubits' => 1, 'gates' => [], 'shots' => 1], 'fake-async');
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

    Event::assertNotDispatched(CircuitFailed::class);
});

it('dispatches CircuitCompleted with the counts and task arn once completed', function () {
    Event::fake();

    $device = new FakeAsynchronousDevice;
    $device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, ['00' => 3, '11' => 7]);

    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $circuit = ['qubits' => 2, 'gates' => [], 'shots' => 10];
    $job = new PollQuantumTask($device->taskArnToReturn, $circuit, 'fake-async');
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

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
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));

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

    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));
})->throws(QuantumExecutionException::class);

it('throws asynchronousUnsupported when the resolved driver does not support async execution', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $job = new PollQuantumTask('arn:fake', ['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->handle($manager, app(Dispatcher::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));
})->throws(QuantumExecutionException::class);
