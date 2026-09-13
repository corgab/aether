<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Config\AetherConfig;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Contracts\ValidatesDispatch;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Jobs\Concerns\FailsWithoutRetry;
use Aether\QuantumManager;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

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
     * Retries only cover failures *before* a task is submitted: once
     * submitCircuit() has returned, retrying would risk creating a second
     * billable task, so a post-submission failure fails the job outright
     * (or rethrows when not running under a worker) instead of letting a
     * retryable exception escape.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The CircuitBuilder::toArray() payload to submit; the shape is re-verified when the circuit is rebuilt, since it has travelled through the queue.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     */
    public function __construct(
        public readonly array $circuit,
        public readonly ?string $driver = null,
    ) {
        // Constructors get no method injection, so the queue name is the one
        // setting resolved from the container by hand.
        $this->onQueue(app(AetherConfig::class)->queue());
    }

    /**
     * Execute the job.
     */
    public function handle(QuantumManager $manager, QuantumTaskRecorder $recorder, AetherConfig $config): void
    {
        $driverName = $this->driver ?? $config->defaultDriver();

        try {
            $device = $manager->driver($driverName);
        } catch (DriverNotFoundException $e) {
            $this->failWithoutRetry($e);

            return;
        }

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            $this->failWithoutRetry(QuantumExecutionException::asynchronousUnsupported($driverName));

            return;
        }

        try {
            if ($device instanceof ValidatesDispatch) {
                $device->validateDispatch($this->pollConnection());
            }

            $builder = CircuitBuilder::fromArray($this->circuit, $device, $driverName);
            $taskArn = $device->submitCircuit($builder);
        } catch (InvalidDriverConfigException|InvalidCircuitException $e) {
            // A malformed payload, a configuration fault or a rejected circuit
            // is deterministic: retrying would only replay the same failure
            // $tries times. Everything else (a dropped connection, a Python
            // crash) keeps the retry budget.
            $this->failWithoutRetry($e);

            return;
        }

        try {
            // Best-effort by design: the remote task already exists at this point,
            // so the recorder reports and swallows a database failure rather than
            // letting the job retry and submit a second billable task.
            $recorder->recordSubmission($taskArn, $driverName, $this->circuit, $this->circuit['shots']);

            $this->schedulePolling($taskArn, $config);
        } catch (\Throwable $e) {
            $exception = QuantumExecutionException::pollingNotScheduled($taskArn, $driverName, $e);

            $recorder->recordProgress($taskArn, TaskStatus::Created, null, $exception->getMessage());

            // Outside a real worker there is nothing to mark as failed: with no
            // queue job, or on the sync connection (where the poll job has just
            // run inline and any exception is its own, not a scheduling one),
            // rethrow so the caller sees it and the worker, if any, reports it.
            if ($this->job === null || $this->job instanceof SyncJob) {
                throw $exception;
            }

            // fail() bypasses the worker's reporting path, so report here or the
            // untracked billable task never reaches the application's logs.
            report($exception);

            $this->fail($exception);
        }
    }

    /**
     * The queue connection the polling job will run on: the one this job was
     * dispatched with, or the application default. Read from the dispatch
     * options rather than the running job, so a synchronous dispatch of this
     * job does not drag the poll onto the sync connection.
     */
    private function pollConnection(): ?string
    {
        $connection = $this->connection ?? $this->job?->getConnectionName();

        if ($connection !== null && $connection !== 'sync') {
            return $connection;
        }

        $default = config('queue.default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Queue the first status poll for the submitted task.
     *
     * Built inside this method so the returned PendingDispatch's destructor
     * — which actually performs the queue push — runs while still inside the
     * caller's try block, letting a push failure be caught there.
     */
    private function schedulePolling(string $taskArn, AetherConfig $config): void
    {
        // The poll follows the submission onto the connection it was
        // dispatched on, so the whole flow runs where the caller put it and
        // the cache store check above holds for the job reading the result.
        PollQuantumTask::dispatch($taskArn, $this->circuit, $this->driver)
            ->onConnection($this->connection)
            ->delay($config->pollInterval());
    }
}
