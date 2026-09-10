<?php

declare(strict_types=1);

namespace Aether\Tasks;

use Aether\Models\QuantumTask;

/**
 * Mirrors the lifecycle of an asynchronous task into the quantum_tasks table.
 *
 * Both queue jobs record through this class so the two rules of persistence
 * live in one place: nothing is written unless `aether.persist_tasks` is on,
 * and a database failure is reported and swallowed. The remote task already
 * exists (or is already being polled) by the time either method runs, so a
 * failed write must never fail the job, retry a billable submission, or
 * suppress the CircuitCompleted event.
 */
class QuantumTaskRecorder
{
    /**
     * Record a freshly submitted task.
     *
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The CircuitBuilder::toArray() payload that was submitted.
     */
    public function recordSubmission(string $taskArn, string $driver, array $circuit, int $shots): void
    {
        $this->write(static function () use ($taskArn, $driver, $circuit, $shots): void {
            QuantumTask::query()->create([
                'task_arn' => $taskArn,
                'driver' => $driver,
                'status' => TaskStatus::Created,
                'circuit' => $circuit,
                'shots' => $shots,
                'submitted_at' => now(),
            ]);
        });
    }

    /**
     * Mirror the state the backend last reported onto the task's row.
     *
     * The status column always reflects the backend; our own polling problems
     * (exhausted budget, malformed response) only ever populate error and
     * failed_at. A task that was never recorded (persistence enabled after
     * submission, or the insert failed) is left alone.
     *
     * @param  array<string, int>|null  $counts
     */
    public function recordProgress(string $taskArn, TaskStatus $status, ?array $counts = null, ?string $error = null): void
    {
        $this->write(static function () use ($taskArn, $status, $counts, $error): void {
            $task = QuantumTask::query()->where('task_arn', $taskArn)->first();

            if ($task === null) {
                return;
            }

            $task->status = $status;

            if ($counts !== null) {
                $task->counts = $counts;
                $task->completed_at = now();
            }

            if ($error !== null) {
                $task->error = $error;
                $task->failed_at = now();
            }

            $task->save();
        });
    }

    /**
     * Whether tasks are persisted at all (`aether.persist_tasks`).
     */
    public function enabled(): bool
    {
        return (bool) config('aether.persist_tasks', false);
    }

    /**
     * Run a write when persistence is enabled, reporting and swallowing any
     * failure so the caller's job is never affected.
     *
     * @param  \Closure(): void  $write
     */
    private function write(\Closure $write): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $write();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
