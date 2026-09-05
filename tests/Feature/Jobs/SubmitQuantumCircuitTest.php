<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Exceptions\PythonProcessTimedOutException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\QuantumManager;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Aether\Tests\Feature\Jobs\FakeSynchronousOnlyDevice;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Queue as QueueFacade;

it('submits the circuit and queues a poll job with the configured delay', function () {
    QueueFacade::fake();

    config(['aether.poll_interval' => 7]);

    $device = new FakeAsynchronousDevice;
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->handle($manager);

    expect($device->submittedCircuits)->toHaveCount(1)
        ->and($device->submittedCircuits[0])->toBeInstanceOf(CircuitBuilder::class);

    QueueFacade::assertPushed(
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

it('outlives the Python process by a margin when no submit timeout is configured', function () {
    config(['aether.process_timeout' => 120, 'aether.submit_timeout' => 0, 'queue.default' => 'sync']);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100]);

    expect($job->timeout)->toBe(150)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('uses the configured submit timeout when it is a positive number', function (mixed $configured, int $expected) {
    config(['aether.process_timeout' => 120, 'aether.submit_timeout' => $configured, 'queue.default' => 'sync']);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100]);

    expect($job->timeout)->toBe($expected);
})->with([
    'integer' => [600, 600],
    'numeric string from env' => ['45', 45],
    'blank env falls back' => ['', 150],
    'zero falls back' => [0, 150],
    'negative falls back' => [-5, 150],
]);

it('stays unlimited when the Python process itself is unlimited', function () {
    config(['aether.process_timeout' => 0, 'aether.submit_timeout' => 0, 'queue.default' => 'sync']);

    expect((new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100]))->timeout)->toBe(0);
});

it('keeps the derived timeout under the default connection retry_after', function (int $processTimeout, int $retryAfter, int $expected) {
    config([
        'aether.process_timeout' => $processTimeout,
        'aether.submit_timeout' => 0,
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => $retryAfter,
    ]);

    expect((new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100]))->timeout)->toBe($expected);
})->with([
    'fresh app defaults' => [300, 90, 80],
    'retry_after above the derived value' => [300, 600, 330],
    'unlimited process, finite retry_after' => [0, 90, 80],
    'retry_after too small for the margin' => [300, 5, 1],
]);

it('lets an explicit submit timeout override the retry_after cap', function () {
    config([
        'aether.submit_timeout' => 400,
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 90,
    ]);

    expect((new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100]))->timeout)->toBe(400);
});

it('carries its timeout and fail-on-timeout flag into the queue payload', function () {
    config(['aether.process_timeout' => 200, 'aether.submit_timeout' => 0, 'queue.default' => 'sync']);

    $queue = new class extends Queue
    {
        public function size($queue = null): int
        {
            return 0;
        }

        public function push($job, $data = '', $queue = null): void {}

        public function pushRaw($payload, $queue = null, array $options = []): void {}

        public function later($delay, $job, $data = '', $queue = null): void {}

        public function pop($queue = null): null
        {
            return null;
        }

        /** @return array<string, mixed> */
        public function payloadFor(object $job): array
        {
            return json_decode($this->createPayload($job, 'default'), true, flags: JSON_THROW_ON_ERROR);
        }
    };
    $queue->setContainer(app());

    $payload = $queue->payloadFor(new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'local'));

    expect($payload['timeout'])->toBe(230)
        ->and($payload['failOnTimeout'])->toBeTrue();
});

it('fails without retry when the Python process times out during submission under a worker', function () {
    QueueFacade::fake();

    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = PythonProcessTimedOutException::afterSeconds('circuit.py', 300);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with($device->throwOnSubmit);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);
    $job->handle($manager);

    QueueFacade::assertNotPushed(PollQuantumTask::class);
});

it('rethrows a submission timeout when there is no queue job to fail', function () {
    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = PythonProcessTimedOutException::afterSeconds('circuit.py', 300);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');

    expect(fn () => $job->handle($manager))->toThrow(PythonProcessTimedOutException::class);
});

it('still retries an ordinary submission failure', function () {
    $device = new FakeAsynchronousDevice;
    $device->throwOnSubmit = QuantumExecutionException::fromPythonError('submit.py', 'connection reset', 1);
    $manager = app(QuantumManager::class);
    $manager->extend('fake-async', fn () => $device);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldNotReceive('fail');

    $job = new SubmitQuantumCircuit(['qubits' => 2, 'gates' => [], 'shots' => 100], 'fake-async');
    $job->setJob($queueJob);

    expect(fn () => $job->handle($manager))->toThrow(QuantumExecutionException::class);
});
