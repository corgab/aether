<?php

declare(strict_types=1);

use Aether\Events\CircuitCompleted;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\Jobs\PollQuantumTask;
use Aether\Jobs\SubmitQuantumCircuit;
use Aether\Models\QuantumTask;
use Aether\QuantumManager;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Aether\Tests\Feature\Jobs\FakeAsynchronousDevice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    config()->set('aether.persist_tasks', true);

    Queue::fake();
    Event::fake();

    $this->circuit = ['qubits' => 1, 'gates' => [], 'shots' => 1000];
    $device = new FakeAsynchronousDevice;
    $this->device = $device;
    $this->manager = app(QuantumManager::class);
    // extend() rebinds the closure to the manager, so capture the device by value.
    $this->manager->extend('fake-async', fn () => $device);

    // Submit through the real job, then hand back the poll job it queued so
    // each test can drive the polling state machine directly.
    $this->submit = function (): PollQuantumTask {
        (new SubmitQuantumCircuit($this->circuit, 'fake-async'))->handle($this->manager);

        $pollJob = null;
        Queue::assertPushed(PollQuantumTask::class, function (PollQuantumTask $job) use (&$pollJob) {
            $pollJob = $job;

            return true;
        });

        return $pollJob;
    };

    $this->poll = fn (PollQuantumTask $job) => $job->handle($this->manager, app(Dispatcher::class));
});

// -------------------------------------------------------------------------
// Submission
// -------------------------------------------------------------------------

it('records the submitted task with its circuit, driver and shots', function () {
    ($this->submit)();

    $this->assertDatabaseCount('quantum_tasks', 1);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->task_arn)->toBe($this->device->taskArnToReturn)
        ->and($task->driver)->toBe('fake-async')
        ->and($task->status)->toBe(TaskStatus::Created)
        ->and($task->shots)->toBe(1000)
        ->and($task->circuit)->toBe($this->circuit)
        ->and($task->submitted_at)->not->toBeNull()
        ->and($task->counts)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->failed_at)->toBeNull()
        ->and($task->error)->toBeNull();
});

it('does not record anything when persist_tasks is disabled', function () {
    config()->set('aether.persist_tasks', false);

    ($this->submit)();

    $this->assertDatabaseCount('quantum_tasks', 0);
});

it('still dispatches the poll job when the insert fails', function () {
    Schema::dropIfExists('quantum_tasks');

    ($this->submit)();

    Queue::assertPushed(PollQuantumTask::class);
    expect($this->device->submittedCircuits)->toHaveCount(1);
});

// -------------------------------------------------------------------------
// Polling
// -------------------------------------------------------------------------

it('runs no query at all from the poll job when persist_tasks is disabled', function () {
    config()->set('aether.persist_tasks', false);
    $job = ($this->submit)();

    DB::enableQueryLog();
    ($this->poll)($job);

    expect(DB::getQueryLog())->toBeEmpty();
    Event::assertDispatched(CircuitCompleted::class);
});

it('marks the task completed with its counts', function () {
    $job = ($this->submit)();

    ($this->poll)($job);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->counts)->toBe(['00' => 5, '11' => 5])
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->error)->toBeNull()
        ->and($task->failed_at)->toBeNull();

    Event::assertDispatched(CircuitCompleted::class);
});

it('mirrors an intermediate backend status while the job is released', function () {
    $this->device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);
    $job = ($this->submit)()->withFakeQueueInteractions();

    ($this->poll)($job);

    $job->assertReleased();
    expect(QuantumTask::query()->firstOrFail()->status)->toBe(TaskStatus::Running);
});

it('keeps the backend status and records the error when polling is exhausted', function () {
    config()->set('aether.max_poll_attempts', 1);
    $this->device->snapshotToReturn = new TaskSnapshot(TaskStatus::Running);
    $job = ($this->submit)();

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldNotReceive('release');
    $job->setJob($queueJob);

    expect(fn () => ($this->poll)($job))->toThrow(QuantumExecutionException::class);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->error)->toContain('did not reach a terminal state')
        ->and($task->failed_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull();
});

it('records the backend failure state and message', function () {
    $this->device->snapshotToReturn = new TaskSnapshot(TaskStatus::Cancelled);
    $job = ($this->submit)();

    expect(fn () => ($this->poll)($job))->toThrow(TaskFailedException::class);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Cancelled)
        ->and($task->error)->toContain('CANCELLED')
        ->and($task->failed_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->counts)->toBeNull();
});

it('records a completed task that returned no counts as an error', function () {
    $this->device->snapshotToReturn = new TaskSnapshot(TaskStatus::Completed, null);
    $job = ($this->submit)();

    expect(fn () => ($this->poll)($job))->toThrow(QuantumExecutionException::class);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->counts)->toBeNull()
        ->and($task->error)->toContain('returned no measurement counts')
        ->and($task->failed_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull();
});

it('still dispatches CircuitCompleted when the update fails', function () {
    $job = ($this->submit)();

    Schema::dropIfExists('quantum_tasks');

    ($this->poll)($job);

    Event::assertDispatched(CircuitCompleted::class);
});
