<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Config\AetherConfig;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitCompleted;
use Aether\Events\CircuitFailed;
use Aether\Exceptions\DriverNotFoundException;
use Aether\Exceptions\InvalidDriverConfigException;
use Aether\Exceptions\MalformedResponseException;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\Jobs\Concerns\FailsWithoutRetry;
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
        $config = app(AetherConfig::class);
        $this->onQueue($config->queue());
        $this->maxExceptions = $config->maxPollExceptions();
    }

    /**
     * Determine the number of times the job may be attempted.
     *
     * Called by the queue worker with no arguments, so the setting is read
     * from the container rather than injected.
     */
    public function tries(): int
    {
        return app(AetherConfig::class)->maxPollAttempts();
    }

    /**
     * Determine the number of seconds to wait before retrying a transient
     * exception, matching the delay used between ordinary polls.
     */
    public function backoff(): int
    {
        return app(AetherConfig::class)->pollInterval();
    }

    /**
     * Execute the job.
     */
    public function handle(QuantumManager $manager, Dispatcher $events, QuantumTaskRecorder $recorder, AetherConfig $config): void
    {
        $driverName = $this->driver ?? $config->defaultDriver();

        // Resolving an unregistered driver is a failure no retry will cure.
        try {
            $device = $manager->driver($this->driver);
        } catch (DriverNotFoundException $e) {
            $this->abandonTask($events, $recorder, $driverName, null, $e);

            return;
        }

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            $this->abandonTask(
                $events,
                $recorder,
                $driverName,
                null,
                QuantumExecutionException::asynchronousUnsupported($driverName),
            );

            return;
        }

        // Likewise for the poll itself: a missing config key, a missing Python
        // binary, or a response the driver cannot read fail at once. Anything
        // else is transient and left to propagate so the worker retries it
        // with backoff().
        try {
            $snapshot = $device->checkTask($this->taskArn);
        } catch (InvalidDriverConfigException|MalformedResponseException $e) {
            $this->abandonTask($events, $recorder, $driverName, null, $e);

            return;
        }

        // The recorder mirrors the backend status onto the quantum_tasks row
        // (when persistence is on) and swallows database failures, so it can
        // never fail the job or suppress the CircuitCompleted event below.
        if (! $snapshot->status->isTerminal()) {
            $maxAttempts = $config->maxPollAttempts();

            if ($this->attempts() >= $maxAttempts) {
                $this->abandonTask(
                    $events,
                    $recorder,
                    $driverName,
                    $snapshot->status,
                    QuantumExecutionException::pollingExhausted($this->taskArn, $this->attempts()),
                );

                return;
            }

            $recorder->recordProgress($this->taskArn, $snapshot->status);
            $this->release($this->backoff());

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

            return;
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

            return;
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
     * Final failure hook, invoked by the worker once the job is failed for
     * good — including after a transient exception has been retried
     * `aether.max_poll_exceptions` times.
     *
     * Idempotent with the recorder calls already made by the deliberate
     * failure paths: only writes when the row has no error recorded
     * yet, so a transient failure that exhausts its budget after one of
     * those paths already ran does not clobber the original message.
     */
    public function failed(Throwable $exception): void
    {
        app(QuantumTaskRecorder::class)->recordFailureIfEmpty($this->taskArn, $exception->getMessage());
    }

    /**
     * Record a task that ended without a result, announce it via CircuitFailed,
     * and fail the job without retry.
     */
    private function abandonTask(
        Dispatcher $events,
        QuantumTaskRecorder $recorder,
        string $driverName,
        ?TaskStatus $status,
        Throwable $exception,
    ): void {
        $recorder->recordProgress($this->taskArn, $status, null, $exception->getMessage());

        try {
            $events->dispatch(new CircuitFailed(
                $driverName,
                $this->circuit,
                $this->taskArn,
                $status ?? TaskStatus::Failed,
                $exception->getMessage(),
            ));
        } catch (Throwable $listenerFailure) {
            report($listenerFailure);
        }

        $this->failWithoutRetry($exception);
    }
}
