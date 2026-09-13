<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Config\DriverConfig;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Contracts\ValidatesDispatch;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * Quantum driver for the local Braket simulator.
 * Simulates asynchronous execution by running circuits synchronously and caching
 * the results under a synthetic "local:<uuid>" identifier for `task_ttl` seconds for checkTask().
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
 *
 * @extends AbstractQuantumDriver<DriverConfig>
 */
class LocalSimulatorDriver extends AbstractQuantumDriver implements AsynchronousDevice, ValidatesDispatch
{
    private const ARN_PREFIX = 'local:';

    private const CACHE_PREFIX = 'aether:local-task:';

    /**
     * Seconds a dispatched result stays available to the polling job when the
     * driver config sets no `task_ttl`.
     */
    public const DEFAULT_TASK_TTL = 3600;

    /**
     * Like every driver, this one reads its options from the injected config
     * array and reaches Laravel only through injected collaborators: the
     * cache store the dispatched results live in is passed in here, so the
     * driver needs neither a facade root nor the global config() helper.
     *
     * The store is fixed for the driver's lifetime (QuantumManager resolves
     * the application's default store when it builds the driver), so a
     * default-store switch made afterwards is picked up only once the driver
     * is rebuilt, e.g. after Quantum::forgetDrivers().
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        PythonExecutor $bridge,
        array $config,
        private readonly CacheRepository $cache,
    ) {
        parent::__construct($bridge, $config);
    }

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

        $this->cache->put($this->cacheKey($taskArn), $result->counts(), $this->taskTtl());

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

        $counts = $this->cache->get($this->cacheKey($taskArn));

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

        $store = $this->cache->getStore();

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

    private function configuredCacheStoreName(): ?string
    {
        $store = $this->config->get('cache_store');

        return is_string($store) && trim($store) !== '' ? trim($store) : null;
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

    /**
     * Retention of a dispatched result, from the driver's own `task_ttl`
     * option (`aether.drivers.local.task_ttl`).
     */
    private function taskTtl(): int
    {
        $ttl = $this->config->get('task_ttl');

        // A non-positive value falls back to the default rather than being
        // taken literally: 0 (or negative) as a cache TTL is not a
        // meaningful "expire immediately" request here, it is what a blank
        // or garbage config value coerces to, and every other numeric
        // driver option in this codebase treats non-positive the same way
        // (entropy_qubits, max_qubits).
        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : self::DEFAULT_TASK_TTL;
    }
}
