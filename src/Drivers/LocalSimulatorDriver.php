<?php

declare(strict_types=1);

namespace Aether\Drivers;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\PythonExecutor;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Tasks\TaskSnapshot;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

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
 *    synthetic "local:<uuid>" identifier for `task_ttl` seconds, and returns
 *    that identifier as if it were a task ARN.
 *  - checkTask() looks the identifier up in the cache and immediately
 *    reports it as Completed (or Failed if the key is missing/expired).
 *
 * No process ever actually queues or polls anything; check.py explicitly
 * refuses to run for the "local" driver (see bin/python/check.py).
 */
class LocalSimulatorDriver extends AbstractQuantumDriver implements AsynchronousDevice
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

    public function submitCircuit(CircuitBuilder $circuit): string
    {
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
        $ttl = $this->config['task_ttl'] ?? null;

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : self::DEFAULT_TASK_TTL;
    }
}
