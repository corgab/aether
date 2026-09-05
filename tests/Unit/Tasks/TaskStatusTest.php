<?php

declare(strict_types=1);

use Aether\Tasks\TaskStatus;

it('maps every Braket task state to an enum case', function (string $state, TaskStatus $expected) {
    expect(TaskStatus::from($state))->toBe($expected);
})->with([
    ['CREATED', TaskStatus::Created],
    ['QUEUED', TaskStatus::Queued],
    ['RUNNING', TaskStatus::Running],
    ['COMPLETED', TaskStatus::Completed],
    ['FAILED', TaskStatus::Failed],
    ['CANCELLING', TaskStatus::Cancelling],
    ['CANCELLED', TaskStatus::Cancelled],
]);

it('throws for an unknown task state', function () {
    TaskStatus::from('EXPLODED');
})->throws(ValueError::class);

it('knows which states are terminal', function (TaskStatus $status, bool $terminal) {
    expect($status->isTerminal())->toBe($terminal);
})->with([
    [TaskStatus::Created, false],
    [TaskStatus::Queued, false],
    [TaskStatus::Running, false],
    [TaskStatus::Completed, true],
    [TaskStatus::Failed, true],
    [TaskStatus::Cancelling, false],
    [TaskStatus::Cancelled, true],
]);

it('knows which state is successful', function () {
    expect(TaskStatus::Completed->isSuccessful())->toBeTrue()
        ->and(TaskStatus::Failed->isSuccessful())->toBeFalse()
        ->and(TaskStatus::Cancelling->isSuccessful())->toBeFalse()
        ->and(TaskStatus::Cancelled->isSuccessful())->toBeFalse()
        ->and(TaskStatus::Running->isSuccessful())->toBeFalse();
});

it('covers exactly the Braket task states', function () {
    // Guards the set, not the declaration order: the enum must track the
    // QuantumTaskStatus values of the Braket API verbatim.
    expect(array_column(TaskStatus::cases(), 'value'))->toEqualCanonicalizing([
        'CREATED',
        'QUEUED',
        'RUNNING',
        'COMPLETED',
        'FAILED',
        'CANCELLING',
        'CANCELLED',
    ]);
});
