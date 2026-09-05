<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitCompleted;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\MalformedResponseException;
use Aether\Exceptions\PythonEnvironmentException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\Jobs\Concerns\FailsWithoutRetry;
use Aether\Models\QuantumTask;
use Aether\QuantumManager;
use Aether\Results\CircuitResult;
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
 * fires {@see CircuitCompleted}; a non-successful terminal state raises
 * {@see TaskFailedException}.
 *
 * The high attempt allowance exists purely to budget the polling loop.
 * Genuine failures fall into two classes: deliberate terminal outcomes and
 * configuration/environment errors fail the job outright via
 * {@see FailsWithoutRetry}, while everything else (a Python subprocess
 * error, a timeout, AWS throttling, a cache hiccup) is transient and is
 * left to propagate so the worker retries it, capped by {@see $maxExceptions}
 * with backoff().
 */
class PollQuantumTask implements ShouldQueue
{
    use FailsWithoutRetry;
    use Queueable;

    /**
     * The maximum number of unhandled (transient) exceptions before failing
     * the job.
     *
     * Polling re-queues the job through release(), which does not increment
     * the exception count, so every attempt budgeted by tries() stays
     * available for the loop. A transient exception thrown from checkTask(),
     * by contrast, is retried by the worker with backoff(); Laravel counts
     * those exceptions per job for its whole lifetime (the counter is not
     * reset by a later successful poll), so this is a total budget across
     * the poll, not a per-incident one.
     *
     * Laravel's queue payload builder only reads this as a plain property
     * (Illuminate\Queue\Queue::createObjectPayload() via
     * ReadsClassAttributes::getAttributeValue(), which never checks
     * method_exists() for maxExceptions the way it does for tries()/
     * backoff()), so it is set here in the constructor rather than exposed
     * as a maxExceptions() method.
     */
    public int $maxExceptions;

    /**
     * Create a new job instance.
     *
     * @param  string  $taskArn  The task identifier returned by submitCircuit().
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The original CircuitBuilder::toArray() payload.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     */
    public function __construct(
        public readonly string $taskArn,
        public readonly array $circuit,
        public readonly ?string $driver = null,
    ) {
        $this->onQueue(config('aether.queue'));
        $this->maxExceptions = (int) config('aether.max_poll_exceptions', 5);
    }

    /**
     * Determine the number of times the job may be attempted.
     */
    public function tries(): int
    {
        return (int) config('aether.max_poll_attempts', 720);
    }

    /**
     * Determine the number of seconds to wait before retrying a transient
     * exception, matching the delay used between ordinary polls.
     */
    public function backoff(): int
    {
        return (int) config('aether.poll_interval', 5);
    }

    /**
     * Execute the job.
     */
    public function handle(QuantumManager $manager, Dispatcher $events): void
    {
        $driverName = $this->driver ?? config('aether.default', 'local');

        // Resolving an unregistered driver is a failure no retry will cure.
        try {
            $device = $manager->driver($this->driver);
        } catch (DriverNotFoundException $e) {
            $this->persist(null, null, $e->getMessage());
            $this->failWithoutRetry($e);

            return;
        }

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            $this->failWithoutRetry(QuantumExecutionException::asynchronousUnsupported($driverName));

            return;
        }

        // Likewise for the poll itself: a missing config key, a missing Python
        // binary, or a response the driver cannot read fail at once. Anything
        // else is transient and left to propagate so the worker retries it
        // with backoff().
        try {
            $snapshot = $device->checkTask($this->taskArn);
        } catch (InvalidDriverConfigException|PythonEnvironmentException|MalformedResponseException $e) {
            $this->persist(null, null, $e->getMessage());
            $this->failWithoutRetry($e);

            return;
        }

        if (! $snapshot->status->isTerminal()) {
            $maxAttempts = $this->tries();

            if ($this->attempts() >= $maxAttempts) {
                $e = QuantumExecutionException::pollingExhausted($this->taskArn, $this->attempts());
                $this->persist($snapshot->status, null, $e->getMessage());
                $this->failWithoutRetry($e);

                return;
            }

            $this->persist($snapshot->status);
            $this->release($this->backoff());

            return;
        }

        if (! $snapshot->status->isSuccessful()) {
            $e = TaskFailedException::forTask($this->taskArn, $snapshot->status);
            $this->persist($snapshot->status, null, $e->getMessage());
            $this->failWithoutRetry($e);

            return;
        }

        if ($snapshot->counts === null) {
            $e = QuantumExecutionException::malformedResponse(
                'checkTask',
                "task [{$this->taskArn}] completed but returned no measurement counts."
            );
            $this->persist($snapshot->status, null, $e->getMessage());
            $this->failWithoutRetry($e);

            return;
        }

        $this->persist($snapshot->status, $snapshot->counts);

        $events->dispatch(new CircuitCompleted(
            $driverName,
            $this->circuit,
            new CircuitResult($snapshot->counts),
            $this->taskArn,
        ));
    }

    /**
     * Final failure hook, invoked by the worker once the job is failed for
     * good — including after a transient exception has been retried
     * `aether.max_poll_exceptions` times.
     *
     * Idempotent with the persist() calls already made by the deliberate
     * failure paths above: only writes when the row has no error recorded
     * yet, so a transient failure that exhausts its budget after one of
     * those paths already ran does not clobber the original message.
     * Best-effort like persist() itself: never let a reporting problem mask
     * the real failure.
     */
    public function failed(Throwable $exception): void
    {
        if (! config('aether.persist_tasks', false)) {
            return;
        }

        try {
            $task = QuantumTask::query()->where('task_arn', $this->taskArn)->first();

            if ($task === null || $task->error !== null) {
                return;
            }

            $task->error = $exception->getMessage();
            $task->failed_at = now();
            $task->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Mirror the backend state onto the persisted quantum_tasks row, when
     * persistence is enabled.
     *
     * The status column always reflects what the backend last reported; our
     * own polling problems (exhausted budget, malformed response) only ever
     * populate error and failed_at. A null $status leaves the column
     * untouched — used when a config/environment error is raised before a
     * snapshot was ever obtained, so there is no fresher status to report.
     * Persistence is best-effort: a database failure is reported and
     * swallowed so it can never fail the job or suppress the
     * CircuitCompleted event.
     *
     * @param  array<string, int>|null  $counts
     */
    private function persist(?TaskStatus $status, ?array $counts = null, ?string $error = null): void
    {
        if (! config('aether.persist_tasks', false)) {
            return;
        }

        try {
            $task = QuantumTask::query()->where('task_arn', $this->taskArn)->first();

            if ($task === null) {
                return;
            }

            if ($status !== null) {
                $task->status = $status;
            }

            if ($counts !== null) {
                $task->counts = $counts;
                $task->completed_at = now();
            }

            if ($error !== null) {
                $task->error = $error;
                $task->failed_at = now();
            }

            $task->save();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
