<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Models\QuantumTask;
use Aether\QuantumManager;
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
        $this->onQueue(config('aether.queue'));
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

        $taskArn = $device->submitCircuit($builder);

        try {
            $this->persistSubmission($taskArn, $driverName);

            $this->schedulePolling($taskArn);
        } catch (\Throwable $e) {
            $exception = QuantumExecutionException::pollingNotScheduled($taskArn, $driverName, $e);

            $this->persistSchedulingFailure($taskArn, $exception->getMessage());

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
    private function schedulePolling(string $taskArn): void
    {
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

    /**
     * Best-effort record of a post-submission scheduling failure on the
     * persisted quantum_tasks row, when persistence is enabled.
     *
     * A database failure here is reported and swallowed rather than allowed
     * to escape: the job is already being failed for the scheduling error
     * itself, and this bookkeeping must never mask or replace that.
     */
    private function persistSchedulingFailure(string $taskArn, string $message): void
    {
        if (! config('aether.persist_tasks', false)) {
            return;
        }

        try {
            $task = QuantumTask::query()->where('task_arn', $taskArn)->first();

            if ($task === null) {
                return;
            }

            $task->error = $message;
            $task->failed_at = now();
            $task->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
