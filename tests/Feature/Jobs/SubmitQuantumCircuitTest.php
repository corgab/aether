<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;

it('submits the circuit and queues a poll job with the configured delay', function () {
    Queue::fake();

    config(['aether.poll_interval' => 7]);

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->handle($manager);

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
    $job->handle($manager);
})->throws(QuantumExecutionException::class);

it('mentions the unsupported driver name in the exception message', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');

    try {
        $job->handle($manager);
        $this->fail('Expected QuantumExecutionException to be thrown.');
    } catch (QuantumExecutionException $exception) {
        expect($exception->getMessage())->toContain('fake-sync');
    }
});

it('fails without retry when the driver rejects its configuration under a worker', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidDriverConfigException::missingKeys('fake-async', ['bucket']);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnSubmit);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('fails without retry when the circuit is rejected by a ceiling under a worker', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidCircuitException::qubitCeilingExceeded(30, 25, 'fake-async');
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnSubmit);

    $job = new SubmitQuantumCircuit(['qubits' => 30, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

    Queue::assertNotPushed(PollQuantumTask::class);
});

it('fails without retry when the driver has no asynchronous support under a worker', function () {
    $device = new FakeSynchronousOnlyDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-sync', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(
        fn (QuantumExecutionException $e): bool => str_contains($e->getMessage(), 'fake-sync')
    ));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->setJob($queueJob);
    $job->handle($manager);
});

it('fails without retry when the driver does not exist under a worker', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(DriverNotFoundException::class));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'nope');
    $job->setJob($queueJob);
    $job->handle(app(QuantumManager::class));
});

it('fails without retry when the serialized circuit cannot be rebuilt under a worker', function (mixed $gate) {
    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(InvalidCircuitException::class));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [$gate], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

    expect($device->submittedCircuits)->toBeEmpty();
})->with(['unknown gate type' => [['type' => 'nope']], 'gate that is not an array' => ['h']]);

it('rethrows a configuration fault when there is no queue job to fail', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = InvalidDriverConfigException::missingKeys('fake-async', ['bucket']);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager))->toThrow(InvalidDriverConfigException::class);
    Queue::assertNotPushed(PollQuantumTask::class);
});

it('hands the device the connection the job actually runs on before submitting', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

    expect($device->validatedConnections)->toBe(['redis'])
        ->and($device->submittedCircuits)->toHaveCount(1);

    Queue::assertPushed(
        PollQuantumTask::class,
        fn (PollQuantumTask $polled): bool => $polled->connection === 'redis',
    );
});

it('fails without retry when the device rejects the dispatch for its connection', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnValidate = InvalidDriverConfigException::processLocalCacheStore('fake-async', 'redis');
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnValidate);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

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

    expect(fn () => $job->handle($manager))->toThrow(InvalidDriverConfigException::class);
    expect($device->validatedConnections)->toBe(['sync']);
});
