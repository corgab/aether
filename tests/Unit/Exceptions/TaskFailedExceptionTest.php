<?php

declare(strict_types=1);

use Aether\Exceptions\AetherException;
use Aether\Exceptions\TaskFailedException;
use Aether\Tasks\TaskStatus;

it('extends the base Aether exception', function () {
    expect(TaskFailedException::forTask('arn:test', TaskStatus::Failed))
        ->toBeInstanceOf(AetherException::class);
});

it('describes the failed task and its terminal state', function () {
    $exception = TaskFailedException::forTask(
        'arn:aws:braket:us-east-1:123456789012:quantum-task/abc',
        TaskStatus::Cancelled,
    );

    expect($exception->getMessage())
        ->toContain('arn:aws:braket:us-east-1:123456789012:quantum-task/abc')
        ->toContain('CANCELLED');
});

it('appends the backend failure reason to the message', function () {
    $exception = TaskFailedException::forTask('arn:test', TaskStatus::Failed, 'Device is offline');

    expect($exception->getMessage())
        ->toBe('Quantum task [arn:test] terminated with status [FAILED]: Device is offline.');
});

it('exposes the backend failure reason through reason()', function () {
    expect(TaskFailedException::forTask('arn:test', TaskStatus::Failed, 'Device is offline')->reason())
        ->toBe('Device is offline');
});

it('does not double the period when the reason already ends with one', function () {
    $exception = TaskFailedException::forTask('arn:test', TaskStatus::Cancelled, 'Cancelled by user.');

    expect($exception->getMessage())
        ->toBe('Quantum task [arn:test] terminated with status [CANCELLED]: Cancelled by user.');
});

it('keeps the plain message and a null reason when the backend reported none', function (?string $reason) {
    $exception = TaskFailedException::forTask('arn:test', TaskStatus::Failed, $reason);

    expect($exception->getMessage())->toBe('Quantum task [arn:test] terminated with status [FAILED].')
        ->and($exception->reason())->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'blank string' => ['   '],
]);
