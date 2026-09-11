<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Config\AetherConfig;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\QuantumExecutionException;
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
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The CircuitBuilder::toArray() payload to submit.
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
        $device = $manager->driver($this->driver);

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            throw QuantumExecutionException::asynchronousUnsupported($driverName);
        }

        $builder = CircuitBuilder::fromArray($this->circuit, $device, $driverName);

        $taskArn = $device->submitCircuit($builder);

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
     * Queue the first status poll for the submitted task.
     *
     * Built inside this method so the returned PendingDispatch's destructor
     * — which actually performs the queue push — runs while still inside the
     * caller's try block, letting a push failure be caught there.
     */
    private function schedulePolling(string $taskArn, AetherConfig $config): void
    {
        PollQuantumTask::dispatch($taskArn, $this->circuit, $this->driver)
            ->delay($config->pollInterval());
    }
}
