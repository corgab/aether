<?php

declare(strict_types=1);

namespace Aether\Tasks;

/**
 * Lifecycle states of an asynchronous quantum task, mirroring AWS Braket task states.
 */
enum TaskStatus: string
{
    case Created = 'CREATED';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    /**
     * Return whether the task has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Return whether the task finished successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }
}
