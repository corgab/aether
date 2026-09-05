<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Contracts\ValidatesDispatch;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidCircuitException;
use Aether\Exceptions\InvalidDriverConfigException;
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
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $circuit  The CircuitBuilder::toArray() payload to submit; its shape is verified when the circuit is rebuilt.
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

        try {
            $device = $manager->driver($this->driver);
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

        $this->persistSubmission($taskArn, $driverName);

        // The poll follows the submission onto the connection it was
        // dispatched on, so the whole flow runs where the caller put it and
        // the cache store check above holds for the job reading the result.
        PollQuantumTask::dispatch($taskArn, $this->circuit, $this->driver)
            ->onConnection($this->connection)
            ->delay((int) config('aether.poll_interval', 5));
    }

    /**
     * The queue connection the polling job will run on: the one this job was
     * dispatched with, or the application default. Read from the dispatch
     * options rather than the running job, so a synchronous dispatch of this
     * job does not drag the poll onto the sync connection.
     */
    private function pollConnection(): ?string
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $default = config('queue.default');

        return is_string($default) && $default !== '' ? $default : null;
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
