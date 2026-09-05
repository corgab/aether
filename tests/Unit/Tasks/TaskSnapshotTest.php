<?php

declare(strict_types=1);

use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;

it('exposes the status and measurement counts', function () {
    $snapshot = new TaskSnapshot(TaskStatus::Completed, ['00' => 500, '11' => 500]);

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['00' => 500, '11' => 500]);
});

it('defaults counts to null while the task is still in flight', function () {
    $snapshot = new TaskSnapshot(TaskStatus::Running);

    expect($snapshot->counts)->toBeNull();
});

it('builds itself from a check script response', function () {
    $snapshot = TaskSnapshot::fromResponse(['status' => 'COMPLETED', 'counts' => ['0' => 10]]);

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['0' => 10]);
});

it('builds an in-flight snapshot from a response without counts', function () {
    $snapshot = TaskSnapshot::fromResponse(['status' => 'QUEUED']);

    expect($snapshot->status)->toBe(TaskStatus::Queued)
        ->and($snapshot->counts)->toBeNull();
});

it('defaults the backend failure reason to null', function () {
    $snapshot = new TaskSnapshot(TaskStatus::Failed);

    expect($snapshot->error)->toBeNull();
});

it('reads the backend failure reason from a check script response', function () {
    $snapshot = TaskSnapshot::fromResponse(['status' => 'FAILED', 'error' => '  Device is offline  ']);

    expect($snapshot->status)->toBe(TaskStatus::Failed)
        ->and($snapshot->error)->toBe('Device is offline')
        ->and($snapshot->counts)->toBeNull();
});

it('ignores an error the check script did not report as a non-empty string', function (mixed $error) {
    $snapshot = TaskSnapshot::fromResponse(['status' => 'FAILED', 'error' => $error]);

    expect($snapshot->error)->toBeNull();
})->with([
    'empty string' => [''],
    'blank string' => ['   '],
    'array' => [['Device is offline']],
    'integer' => [500],
    'null' => [null],
]);

it('leaves the error null when the response carries none', function () {
    expect(TaskSnapshot::fromResponse(['status' => 'CANCELLED'])->error)->toBeNull();
});
