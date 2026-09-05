<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\ValidatesDispatch;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Quantum driver for the local Braket simulator.
 *
 * Local execution is synchronous and instantaneous — there is no real
 * remote task to submit or poll. To let application code exercise the same
 * submitCircuit()/checkTask() workflow used against real QPUs while
 * developing locally, this driver *simulates* asynchronous execution:
 *
 *  - submitCircuit() runs the circuit synchronously (via runCircuit(), so the
 *    synchronous CircuitExecuted event does not fire for what is, to the
 *    caller, an asynchronous dispatch), caches the resulting counts under a
 *    synthetic "local:<uuid>" identifier, and returns that identifier as if
 *    it were a task ARN.
 *  - checkTask() looks the identifier up in the cache and immediately
 *    reports it as Completed (or Failed if the key is missing/expired).
 *
 * The cache store used to hold those results is configurable via
 * drivers.local.cache_store (null uses the application's default store) and
 * is checked before anything runs: see assertCacheStoreIsShared().
 *
 * No process ever actually queues or polls anything; check.py explicitly
 * refuses to run for the "local" driver (see bin/python/check.py).
 */
class LocalSimulatorDriver extends AbstractQuantumDriver implements AsynchronousDevice, ValidatesDispatch
{
    private const ARN_PREFIX = 'local:';

    private const CACHE_PREFIX = 'aether:local-task:';

    protected function driverName(): string
    {
        return 'local';
    }

    /**
     * Reject a dispatch whose result could never be read back by the polling job.
     */
    public function validateDispatch(?string $queueConnection = null): void
    {
        $this->assertCacheStoreIsShared($queueConnection);
    }

    public function submitCircuit(CircuitBuilder $circuit): string
    {
        $this->assertCacheStoreIsShared();

        $result = $this->runCircuit($circuit);

        $taskArn = self::ARN_PREFIX.(string) Str::uuid();

        $this->cache()->put($this->cacheKey($taskArn), $result->counts(), $this->taskTtl());

        return $taskArn;
    }

    public function checkTask(string $taskArn): TaskSnapshot
    {
        if (! str_starts_with($taskArn, self::ARN_PREFIX)) {
            throw QuantumExecutionException::malformedResponse(
                'checkTask',
                "Expected a local: task identifier, got [{$taskArn}]."
            );
        }

        $counts = $this->cache()->get($this->cacheKey($taskArn));

        if (! is_array($counts)) {
            return new TaskSnapshot(TaskStatus::Failed);
        }

        /** @var array<string, int> $counts */
        return new TaskSnapshot(TaskStatus::Completed, $counts);
    }

    /**
     * Guard against caching an asynchronous result where the polling job
     * would never be able to read it back.
     *
     * The store must resolve and must not be the "null" store, which
     * discards every write. The "array" store is process-local, so when the
     * submission job runs on a queue connection that crosses process
     * boundaries (any driver other than "sync") the polling job would miss;
     * that check needs the real connection, so it only runs when one is
     * given, and an explicitly configured cache_store — "array" included —
     * is trusted as a deliberate opt-out of it.
     *
     * @param  string|null  $queueConnection  The connection the submission job runs on, or null when unknown.
     *
     * @throws InvalidDriverConfigException
     */
    protected function assertCacheStoreIsShared(?string $queueConnection = null): void
    {
        $name = $this->configuredCacheStoreName();

        try {
            $store = $this->cache()->getStore();
        } catch (Throwable $e) {
            // An undefined store or unsupported driver is an InvalidArgumentException;
            // a driver whose extension is missing surfaces as an Error. Both are config.
            throw InvalidDriverConfigException::unknownCacheStore($this->driverName(), $name ?? $this->defaultCacheStoreName(), $e);
        }

        if ($store instanceof NullStore) {
            throw InvalidDriverConfigException::discardingCacheStore(
                $this->driverName(),
                $name ?? $this->defaultCacheStoreName(),
            );
        }

        if ($name !== null || $queueConnection === null || ! $store instanceof ArrayStore) {
            return;
        }

        $queueDriver = config("queue.connections.{$queueConnection}.driver");

        if (! is_string($queueDriver) || $queueDriver === '' || $queueDriver === 'sync') {
            return;
        }

        throw InvalidDriverConfigException::processLocalCacheStore($this->driverName(), $queueDriver);
    }

    /**
     * Resolve the cache repository asynchronous results are stored in.
     */
    protected function cache(): Repository
    {
        return Cache::store($this->configuredCacheStoreName());
    }

    private function configuredCacheStoreName(): ?string
    {
        return $this->configString('cache_store');
    }

    /**
     * The name of the application's default cache store, for messages.
     */
    private function defaultCacheStoreName(): string
    {
        $default = config('cache.default');

        return is_string($default) && $default !== '' ? $default : 'null';
    }

    private function cacheKey(string $taskArn): string
    {
        return self::CACHE_PREFIX.$taskArn;
    }

    private function taskTtl(): int
    {
        return (int) config('aether.local_task_ttl', 3600);
    }
}
