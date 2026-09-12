<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Config\AetherConfig;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('submits the circuit and queues a poll job with the configured delay', function () {
    Queue::fake();

    config(['aether.poll_interval' => 7]);

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($device->submittedCircuits)->toHaveCount(1)
        ->and($device->submittedCircuits[0])->toBeInstanceOf(CircuitBuilder::class);

    Queue::assertPushed(
        PollQuantumTask::class,
        fn (PollQuantumTask $polled): bool => $polled->taskArn === $device->taskArnToReturn
            && $polled->driver === 'fake-async'
            && $polled->delay === 7
            && $polled->connection === null,
    );
});

it('throws asynchronousUnsupported when the resolved driver does not support async execution', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));
})->throws(QuantumExecutionException::class);

it('mentions the unsupported driver name in the exception message', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');

    try {
        $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));
        $this->fail('Expected QuantumExecutionException to be thrown.');
    } catch (QuantumExecutionException $exception) {
        expect($exception->getMessage())->toContain('fake-sync');
    }
});

it('fails without retrying when the poll job cannot be queued after submission', function () {
    Queue::fake()->beforePushing(function ($job) {
        if ($job instanceof PollQuantumTask) {
            throw new RuntimeException('queue down');
        }
    });

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))
        ->withFakeQueueInteractions();

    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith(QuantumExecutionException::class);
    $job->assertNotReleased();

    expect($device->submittedCircuits)->toHaveCount(1);
});

it('fails without retry when the driver rejects its configuration under a worker', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidDriverConfigException::missingKeys('fake-async', ['bucket']);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnSubmit);
    $queueJob->shouldReceive('getConnectionName')->andReturn(null);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('throws the scheduling failure when handled outside a queue worker', function () {
    Queue::fake()->beforePushing(function ($job) {
        if ($job instanceof PollQuantumTask) {
            throw new RuntimeException('queue down');
        }
    });

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow(QuantumExecutionException::class, 'could not be queued');

    expect($device->submittedCircuits)->toHaveCount(1);
});

it('fails without retry when the circuit is rejected by a ceiling under a worker', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidCircuitException::qubitCeilingExceeded(30, 25, 'fake-async');
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnSubmit);
    $queueJob->shouldReceive('getConnectionName')->andReturn(null);

    $job = new SubmitQuantumCircuit(['qubits' => 30, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('fails without retry when the driver has no asynchronous support under a worker', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(
        fn (QuantumExecutionException $e): bool => str_contains($e->getMessage(), 'fake-sync')
    ));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->setJob($queueJob);
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));
});

it('fails without retry when the driver does not exist under a worker', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(DriverNotFoundException::class));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'nope');
    $job->setJob($queueJob);
    $job->handle(app(QuantumManager::class), app(QuantumTaskRecorder::class), app(AetherConfig::class));
});

it('fails without retry when the serialized circuit cannot be rebuilt under a worker', function (mixed $gate) {
    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(InvalidCircuitException::class));
    $queueJob->shouldReceive('getConnectionName')->andReturn(null);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [$gate], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($device->submittedCircuits)->toBeEmpty();
})->with(['unknown gate type' => [['type' => 'nope']], 'gate that is not an array' => ['h']]);

it('rethrows a configuration fault when there is no queue job to fail', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidDriverConfigException::missingKeys('fake-async', ['bucket']);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow(InvalidDriverConfigException::class);
    Queue::assertNotPushed(PollQuantumTask::class);
});

it('reports the scheduling failure when failing the job under a worker', function () {
    Exceptions::fake();
    Queue::fake()->beforePushing(function ($job) {
        if ($job instanceof PollQuantumTask) {
            throw new RuntimeException('queue down');
        }
    });

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = (new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async'))->withFakeQueueInteractions();
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    $job->assertFailedWith(QuantumExecutionException::class);
    Exceptions::assertReported(QuantumExecutionException::class);
});

it('rethrows instead of failing silently when running on the sync connection', function () {
    Queue::fake()->beforePushing(function ($job) {
        if ($job instanceof PollQuantumTask) {
            throw new RuntimeException('poll job blew up inline');
        }
    });

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob(new SyncJob(app(), '{}', 'sync', 'default'));

    expect(fn () => $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow(QuantumExecutionException::class, 'poll job blew up inline');
    expect($device->submittedCircuits)->toHaveCount(1);
});

it('hands the device the connection the poll will run on and dispatches the poll there', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->onConnection('redis');
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($device->validatedConnections)->toBe(['redis'])
        ->and($device->submittedCircuits)->toHaveCount(1);

    Queue::assertPushed(
        PollQuantumTask::class,
        fn (PollQuantumTask $polled): bool => $polled->connection === 'redis',
    );
});

it('judges a synchronously dispatched submission by the default connection the poll will use', function () {
    Queue::fake();
    config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis']);

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob(new SyncJob(app(), '{}', 'sync', 'default'));
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($device->validatedConnections)->toBe(['redis']);

    Queue::assertPushed(
        PollQuantumTask::class,
        fn (PollQuantumTask $polled): bool => $polled->connection === null,
    );
});

it('still retries when submission itself fails', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = true;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow(RuntimeException::class, 'submission failed');

    expect($device->submittedCircuits)->toHaveCount(0);

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('fails without retry when the device rejects the dispatch for its connection', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnValidate = InvalidDriverConfigException::processLocalCacheStore('fake-async', 'redis');
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnValidate);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->onConnection('redis');
    $job->setJob($queueJob);
    $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class));

    expect($device->submittedCircuits)->toBeEmpty();
    Queue::assertNotPushed(PollQuantumTask::class);
});

it('rethrows a configuration fault on the sync connection so the dispatcher sees it', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidDriverConfigException::missingKeys('fake-async', ['bucket']);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob(new SyncJob(app(), '{}', 'sync', 'default'));

    expect(fn () => $job->handle($manager, app(QuantumTaskRecorder::class), app(AetherConfig::class)))
        ->toThrow(InvalidDriverConfigException::class);
    expect($device->validatedConnections)->toBe(['sync']);
});
