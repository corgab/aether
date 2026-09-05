<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\PythonProcessTimedOutException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\Concerns\FailsWithoutRetry;
use Aether\Models\QuantumTask;
use Aether\QuantumManager;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Submits a circuit for asynchronous execution and schedules the first
 * status poll for it.
 *
 * Dispatched by `CircuitBuilder::dispatch()` (or manually), this job
 * resolves the target driver, submits the circuit, and hands the returned
 * task ARN off to {@see PollQuantumTask} to track until completion.
 */
class SubmitQuantumCircuit implements ShouldQueue
{
    use FailsWithoutRetry;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * A handful of retries absorb transient submission failures (e.g. a
     * dropped connection to the backend) without operator intervention.
     */
    public int $tries = 3;

    /**
     * The number of seconds the worker lets one attempt run before killing it.
     *
     * The local driver runs the whole simulation inline inside this job, so
     * an attempt has to outlive the Python process it spawns: without this
     * the worker's default of 60 seconds would kill any simulation longer
     * than a minute while the bridge itself still allowed process_timeout.
     * Resolved from aether.submit_timeout when set, otherwise derived from
     * process_timeout plus a margin and kept below the default queue
     * connection's retry_after, so a slow attempt is killed by this worker
     * rather than handed to a second one while still running.
     */
    public int $timeout;

    /**
     * A timed-out attempt is failed rather than retried: the next attempt
     * would run the same circuit against the same limit.
     */
    public bool $failOnTimeout = true;

    /**
     * Seconds added to process_timeout so the worker reaps the Python
     * process's own timeout error instead of racing it.
     */
    private const TIMEOUT_MARGIN = 30;

    /**
     * Seconds kept between the derived timeout and the connection's
     * retry_after, so the worker kills the attempt before the queue
     * releases it to another worker.
     */
    private const RETRY_AFTER_MARGIN = 10;

    /**
     * Create a new job instance.
     *
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The CircuitBuilder::toArray() payload to submit.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     */
    public function __construct(
        public readonly array $circuit,
        public readonly ?string $driver = null,
    ) {
        $this->onQueue(config('aether.queue'));
        $this->timeout = self::resolveTimeout();
    }

    /**
     * The configured submit_timeout when positive, otherwise a value derived
     * from process_timeout (0, meaning unlimited, stays unlimited) and capped
     * under the default queue connection's retry_after when that is known.
     */
    private static function resolveTimeout(): int
    {
        $configured = (int) config('aether.submit_timeout', 0);

        if ($configured > 0) {
            return $configured;
        }

        $processTimeout = (int) config('aether.process_timeout', 300);
        $timeout = $processTimeout > 0 ? $processTimeout + self::TIMEOUT_MARGIN : 0;

        $retryAfter = self::defaultConnectionRetryAfter();

        if ($retryAfter !== null && ($timeout === 0 || $timeout >= $retryAfter)) {
            $timeout = max($retryAfter - self::RETRY_AFTER_MARGIN, 1);
        }

        return $timeout;
    }

    /**
     * The retry_after of the default queue connection, or null when the
     * connection does not define one (sync, for instance).
     */
    private static function defaultConnectionRetryAfter(): ?int
    {
        $connection = config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        $retryAfter = config("queue.connections.{$connection}.retry_after");

        return is_numeric($retryAfter) && (int) $retryAfter > 0 ? (int) $retryAfter : null;
    }

    /**
     * Execute the job.
     */
    public function handle(QuantumManager $manager): void
    {
        $driverName = $this->driver ?? config('aether.default', 'local');
        $device = $manager->driver($this->driver);

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            throw QuantumExecutionException::asynchronousUnsupported($driverName);
        }

        $builder = CircuitBuilder::fromArray($this->circuit, $device, $driverName);

        try {
            $taskArn = $device->submitCircuit($builder);
        } catch (PythonProcessTimedOutException $e) {
            // Retrying would run the same circuit against the same limit and,
            // on a remote backend, could duplicate a task the backend already
            // accepted before the process was killed.
            $this->failWithoutRetry($e);

            return;
        }

        $this->persistSubmission($taskArn, $driverName);

        PollQuantumTask::dispatch($taskArn, $this->circuit, $this->driver)
            ->delay((int) config('aether.poll_interval', 5));
    }

    /**
     * Record the submitted task in the quantum_tasks table, when persistence
     * is enabled.
     *
     * Best-effort by design: the remote task already exists at this point, so
     * a database failure is reported and swallowed rather than allowed to
     * retry the job and submit a second billable task.
     */
    private function persistSubmission(string $taskArn, string $driverName): void
    {
        if (! config('aether.persist_tasks', false)) {
            return;
        }

        try {
            QuantumTask::query()->create([
                'task_arn' => $taskArn,
                'driver' => $driverName,
                'status' => TaskStatus::Created,
                'circuit' => $this->circuit,
                'shots' => $this->circuit['shots'],
                'submitted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
