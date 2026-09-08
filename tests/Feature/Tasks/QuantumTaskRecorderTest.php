<?php

declare(strict_types=1);

use Aether\Models\QuantumTask;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    config()->set('aether.persist_tasks', true);

    $this->recorder = app(QuantumTaskRecorder::class);
    $this->circuit = ['qubits' => 2, 'gates' => [], 'shots' => 500];
});

// -------------------------------------------------------------------------
// Gate
// -------------------------------------------------------------------------

it('reflects the persist_tasks setting', function () {
    expect($this->recorder->enabled())->toBeTrue();

    config()->set('aether.persist_tasks', false);

    expect($this->recorder->enabled())->toBeFalse();
});

it('runs no query at all when persistence is disabled', function () {
    config()->set('aether.persist_tasks', false);

    DB::enableQueryLog();
    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);
    $this->recorder->recordProgress('arn:1', TaskStatus::Completed, ['00' => 500]);

    expect(DB::getQueryLog())->toBeEmpty();
    $this->assertDatabaseCount('quantum_tasks', 0);
});

// -------------------------------------------------------------------------
// recordSubmission()
// -------------------------------------------------------------------------

it('records a submitted task as created with its circuit, driver and shots', function () {
    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->task_arn)->toBe('arn:1')
        ->and($task->driver)->toBe('aws')
        ->and($task->status)->toBe(TaskStatus::Created)
        ->and($task->circuit)->toBe($this->circuit)
        ->and($task->shots)->toBe(500)
        ->and($task->submitted_at)->not->toBeNull()
        ->and($task->counts)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->failed_at)->toBeNull()
        ->and($task->error)->toBeNull();
});

// -------------------------------------------------------------------------
// recordProgress()
// -------------------------------------------------------------------------

it('mirrors an intermediate status without touching the outcome columns', function () {
    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);

    $this->recorder->recordProgress('arn:1', TaskStatus::Running);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->counts)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->failed_at)->toBeNull()
        ->and($task->error)->toBeNull();
});

it('records counts and the completion time on success', function () {
    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);

    $this->recorder->recordProgress('arn:1', TaskStatus::Completed, ['00' => 250, '11' => 250]);

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Completed)
        ->and($task->counts)->toBe(['00' => 250, '11' => 250])
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->failed_at)->toBeNull()
        ->and($task->error)->toBeNull();
});

it('records the error and the failure time while keeping the backend status', function () {
    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);

    $this->recorder->recordProgress('arn:1', TaskStatus::Running, null, 'polling exhausted');

    $task = QuantumTask::query()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->error)->toBe('polling exhausted')
        ->and($task->failed_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->counts)->toBeNull();
});

it('leaves a task that was never recorded alone', function () {
    $this->recorder->recordProgress('arn:unknown', TaskStatus::Completed, ['0' => 1]);

    $this->assertDatabaseCount('quantum_tasks', 0);
});

// -------------------------------------------------------------------------
// Failure handling
// -------------------------------------------------------------------------

it('reports and swallows a database failure on either write', function () {
    Schema::dropIfExists('quantum_tasks');

    $reported = [];
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->twice()->andReturnUsing(function (Throwable $e) use (&$reported): void {
        $reported[] = $e;
    });
    app()->instance(ExceptionHandler::class, $handler);

    $this->recorder->recordSubmission('arn:1', 'aws', $this->circuit);
    $this->recorder->recordProgress('arn:1', TaskStatus::Completed, ['0' => 1]);

    expect($reported)->toHaveCount(2)
        ->and($reported[0])->toBeInstanceOf(QueryException::class)
        ->and($reported[1])->toBeInstanceOf(QueryException::class);
});
