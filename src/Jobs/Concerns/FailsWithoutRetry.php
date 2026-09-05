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
     * Without a real queue job (e.g. the sync connection, or handle()
     * invoked directly in a test) there is nothing to fail, so the
     * exception is rethrown and whoever catches it reports it. Under a
     * worker, fail() marks the job failed with no further attempts but
     * bypasses the worker's own exception reporting, hence the explicit
     * report() on that path only.
     */
    protected function failWithoutRetry(Throwable $exception): void
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            throw $exception;
        }

        report($exception);

        $this->fail($exception);
    }
}
