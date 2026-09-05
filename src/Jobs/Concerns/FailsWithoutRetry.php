<?php

declare(strict_types=1);

namespace Aether\Jobs\Concerns;

use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Fails a queued job immediately, bypassing any remaining tries/exceptions
 * budget, for deliberate terminal outcomes that must never be retried.
 */
trait FailsWithoutRetry
{
    /**
     * Report the exception and fail the underlying queue job outright.
     *
     * fail() marks the job failed with no further attempts and bypasses the
     * worker's own exception reporting, hence the explicit report() call
     * here. Without a real queue job (e.g. the sync connection, or handle()
     * invoked directly in a test) there is nothing to fail, so the
     * exception is rethrown instead.
     */
    protected function failWithoutRetry(Throwable $exception): void
    {
        report($exception);

        if ($this->job === null || $this->job instanceof SyncJob) {
            throw $exception;
        }

        $this->fail($exception);
    }
}
