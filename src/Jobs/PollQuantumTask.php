<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitCompleted;
use Aether\Events\CircuitFailed;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\QuantumManager;
use Aether\Results\CircuitResult;
use Aether\Tasks\QuantumTaskRecorder;
use Aether\Tasks\TaskStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Polls an asynchronous quantum task until it reaches a terminal state.
 *
 * Uses Laravel's native job release mechanism to check the task status
 * repeatedly up to `aether.max_poll_attempts`. Once the task completes,
 * fires {@see CircuitCompleted}. A task that ends without a result (a
 * non-successful terminal state, an exhausted polling budget, or a completed
 * task with no counts) fires {@see CircuitFailed} and then raises
 * {@see TaskFailedException} or {@see QuantumExecutionException}.
 *
 * The high attempt allowance exists purely to budget the polling loop, so
 * genuine failures are capped separately by {@see $maxExceptions}.
 */
class PollQuantumTask implements ShouldQueue
{
    use Queueable;

    /**
     * The maximum number of unhandled exceptions before failing the job.
     *
     * Polling re-queues the job through release(), which does not increment
     * the exception count, so every attempt budgeted by tries() stays
     * available for the loop. A thrown exception, by contrast, always signals
     * a genuine failure and must fail the job outright instead of being
     * retried hundreds of times with no backoff.
     */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $taskArn  The task identifier returned by submitCircuit().
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}|string  $circuit  The original CircuitBuilder::toArray() payload or OpenQASM string.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     */
    public function __construct(
        public readonly string $taskArn,
        public readonly array|string $circuit,
        public readonly ?string $driver = null,
    ) {
        $this->onQueue(config('aether.queue'));
    }

    /**
     * Determine the number of times the job may be attempted.
     */
    public function tries(): int
    {
        return (int) config('aether.max_poll_attempts', 720);
    }

    /**
     * Execute the job.
     */
    public function handle(QuantumManager $manager, Dispatcher $events, QuantumTaskRecorder $recorder): void
    {
        $driverName = $this->driver ?? config('aether.default', 'local');
        $device = $manager->driver($this->driver);

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            throw QuantumExecutionException::asynchronousUnsupported($driverName);
        }

        $snapshot = $device->checkTask($this->taskArn);

        // The recorder mirrors the backend status onto the quantum_tasks row
        // (when persistence is on) and swallows database failures, so it can
        // never fail the job or suppress the CircuitCompleted event below.
        if (! $snapshot->status->isTerminal()) {
            $maxAttempts = $this->tries();

            if ($this->attempts() >= $maxAttempts) {
                $this->abandonTask(
                    $events,
                    $recorder,
                    $driverName,
                    $snapshot->status,
                    QuantumExecutionException::pollingExhausted($this->taskArn, $this->attempts()),
                );
            }

            $recorder->recordProgress($this->taskArn, $snapshot->status);
            $this->release((int) config('aether.poll_interval', 5));

            return;
        }

        if (! $snapshot->status->isSuccessful()) {
            $this->abandonTask(
                $events,
                $recorder,
                $driverName,
                $snapshot->status,
                TaskFailedException::forTask($this->taskArn, $snapshot->status),
            );
        }

        if ($snapshot->counts === null) {
            $this->abandonTask(
                $events,
                $recorder,
                $driverName,
                $snapshot->status,
                QuantumExecutionException::malformedResponse(
                    'checkTask',
                    "task [{$this->taskArn}] completed but returned no measurement counts."
                ),
            );
        }

        $recorder->recordProgress($this->taskArn, $snapshot->status, $snapshot->counts);

        $events->dispatch(new CircuitCompleted(
            $driverName,
            $this->circuit,
            new CircuitResult($snapshot->counts),
            $this->taskArn,
        ));
    }

    /**
     * Record a task that ended without a result, announce it, and fail the job.
     *
     * CircuitFailed is the counterpart of CircuitCompleted: it is dispatched
     * before the exception so application code can react to the failure
     * (notify, refund, retry elsewhere) without reading failed_jobs. The
     * exception still propagates so the job is failed and recorded as usual;
     * a listener that throws is reported and swallowed, so it can never
     * replace the task failure as the reason the job failed.
     */
    private function abandonTask(
        Dispatcher $events,
        QuantumTaskRecorder $recorder,
        string $driverName,
        TaskStatus $status,
        Throwable $exception,
    ): never {
        $recorder->recordProgress($this->taskArn, $status, null, $exception->getMessage());

        try {
            $events->dispatch(new CircuitFailed(
                $driverName,
                $this->circuit,
                $this->taskArn,
                $status,
                $exception->getMessage(),
            ));
        } catch (Throwable $listenerFailure) {
            report($listenerFailure);
        }

        throw $exception;
    }
}
