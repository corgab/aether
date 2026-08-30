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
