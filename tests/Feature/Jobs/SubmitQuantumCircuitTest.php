<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Contracts\Queue\Job;
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
            && $polled->delay === 7,
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
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(
        fn (QuantumExecutionException $e): bool => str_contains($e->getMessage(), 'fake-sync')
    ));

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-sync');
    $job->setJob($queueJob);
    $job->handle($manager);
});

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
