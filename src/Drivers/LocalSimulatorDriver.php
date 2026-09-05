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
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
 * drivers.local.cache_store (null uses the application's default store).
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
    public function validateDispatch(): void
    {
        $this->assertCacheStoreIsShared();
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
                'expected a local task identifier of the form "'.self::ARN_PREFIX.'<uuid>", got "'.$taskArn.'".'
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
     * The "null" store discards every write, so it can never work. The
     * "array" store is process-local: when the queue connection crosses
     * process boundaries (any driver other than "sync") the job that submits
     * the circuit and the job that polls it can land in different worker
     * processes and the poll would always miss. An explicitly configured
     * cache_store — including "array" — is trusted as a deliberate opt-out of
     * that second check, but it still has to name a defined, non-null store.
     *
     * The queue check reads the default queue connection; a job dispatched
     * onto another connection with onConnection() is not covered, so name
     * the store explicitly in that setup. The check runs twice on purpose:
     * at dispatch time through validateDispatch(), where the developer is
     * looking, and again in submitCircuit() as the worker-side safety net.
     *
     * @throws InvalidDriverConfigException
     */
    protected function assertCacheStoreIsShared(): void
    {
        $name = $this->configuredCacheStoreName();

        try {
            $store = $this->cache()->getStore();
        } catch (InvalidArgumentException $e) {
            // CacheManager rejects both an undefined store and an unsupported driver.
            throw InvalidDriverConfigException::unknownCacheStore($this->driverName(), $name ?? $this->defaultCacheStoreName(), $e);
        }

        if ($store instanceof NullStore) {
            throw InvalidDriverConfigException::discardingCacheStore(
                $this->driverName(),
                $name ?? $this->defaultCacheStoreName(),
            );
        }

        if ($name !== null || ! $store instanceof ArrayStore) {
            return;
        }

        $queueDriver = $this->defaultQueueDriver();

        if ($queueDriver === null || $queueDriver === 'sync') {
            return;
        }

        throw InvalidDriverConfigException::processLocalCacheStore($this->driverName(), $queueDriver);
    }

    /**
     * The driver of the default queue connection, or null when it cannot be resolved.
     */
    private function defaultQueueDriver(): ?string
    {
        $connection = config('queue.default');

        if (! is_string($connection) || trim($connection) === '') {
            return null;
        }

        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) && trim($driver) !== '' ? $driver : null;
    }

    /**
     * Resolve the cache repository asynchronous results are stored in.
     */
    protected function cache(): Repository
    {
        $root = Cache::getFacadeRoot();

        // Tests may swap the facade with a bare repository instead of the
        // manager; that repository is then the only store there is.
        if (! $root instanceof Factory) {
            return $root;
        }

        return $root->store($this->configuredCacheStoreName());
    }

    /**
     * The name of the application's default cache store, for messages.
     */
    private function defaultCacheStoreName(): string
    {
        $default = config('cache.default');

        return is_string($default) && $default !== '' ? $default : 'null';
    }

    private function configuredCacheStoreName(): ?string
    {
        $value = $this->config['cache_store'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
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
