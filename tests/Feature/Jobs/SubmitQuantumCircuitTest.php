<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
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

    $job->handle($manager);

    $job->assertFailedWith(QuantumExecutionException::class);
    $job->assertNotReleased();

    expect($device->submittedCircuits)->toHaveCount(1);

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

    expect(fn () => $job->handle($manager))
        ->toThrow(QuantumExecutionException::class, 'could not be queued');

    expect($device->submittedCircuits)->toHaveCount(1);
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
    $job->handle($manager);

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

    expect(fn () => $job->handle($manager))
        ->toThrow(QuantumExecutionException::class, 'poll job blew up inline');
    expect($device->submittedCircuits)->toHaveCount(1);
});

it('still retries when submission itself fails', function () {
    Queue::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = true;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager))->toThrow(RuntimeException::class, 'submission failed');

    expect($device->submittedCircuits)->toHaveCount(0);

    Queue::assertNotPushed(PollQuantumTask::class);
});
