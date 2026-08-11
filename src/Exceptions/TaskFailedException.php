<?php

declare(strict_types=1);

namespace Aether\Exceptions;

use Aether\Tasks\TaskStatus;

/**
 * Thrown when an asynchronous quantum task reaches a non-successful terminal state.
 */
class TaskFailedException extends AetherException
{
    /**
     * Create an exception for a task that terminated without completing.
     */
    public static function forTask(string $taskArn, TaskStatus $status): self
    {
        return new self(
            "Quantum task [{$taskArn}] terminated with status [{$status->value}]."
        );
    }
}
