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
