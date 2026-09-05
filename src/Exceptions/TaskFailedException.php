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
     * The backend's own explanation for the failure, when it reported one.
     */
    private ?string $reason = null;

    /**
     * Create an exception for a task that terminated without completing.
     *
     * @param  string|null  $reason  The backend-supplied failure reason, appended to the
     *                               message and readable through reason().
     */
    public static function forTask(string $taskArn, TaskStatus $status, ?string $reason = null): self
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $message = "Quantum task [{$taskArn}] terminated with status [{$status->value}]"
            .($reason === null ? '.' : ': '.rtrim($reason, '.').'.');

        $exception = new self($message);
        $exception->reason = $reason;

        return $exception;
    }

    /**
     * The backend-supplied failure reason, or null when none was reported.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
