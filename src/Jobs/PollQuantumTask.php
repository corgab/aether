<?php

declare(strict_types=1);

namespace Aether\Jobs;

use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Events\CircuitCompleted;
use Aether\Exceptions\QuantumExecutionException;
use Aether\Exceptions\TaskFailedException;
use Aether\QuantumManager;
use Aether\Results\CircuitResult;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polls an asynchronous quantum task until it reaches a terminal state.
 *
 * Uses Laravel's native job release mechanism to check the task status
 * repeatedly up to `aether.max_poll_attempts`. Once the task completes,
 * fires {@see CircuitCompleted}; a non-successful terminal state raises
 * {@see TaskFailedException}.
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
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The original CircuitBuilder::toArray() payload.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     */
    public function __construct(
        public readonly string $taskArn,
        public readonly array $circuit,
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
    public function handle(QuantumManager $manager, Dispatcher $events): void
    {
        $driverName = $this->driver ?? config('aether.default', 'local');
        $device = $manager->driver($this->driver);

        if (! $device instanceof AsynchronousDevice || ! $device instanceof QuantumDevice) {
            throw QuantumExecutionException::asynchronousUnsupported($driverName);
        }

        $snapshot = $device->checkTask($this->taskArn);

        if (! $snapshot->status->isTerminal()) {
            $maxAttempts = $this->tries();

            if ($this->attempts() >= $maxAttempts) {
                throw QuantumExecutionException::pollingExhausted($this->taskArn, $this->attempts());
            }

            $this->release((int) config('aether.poll_interval', 5));

            return;
        }

        if (! $snapshot->status->isSuccessful()) {
            throw TaskFailedException::forTask($this->taskArn, $snapshot->status);
        }

        if ($snapshot->counts === null) {
            throw QuantumExecutionException::malformedResponse(
                'checkTask',
                "task [{$this->taskArn}] completed but returned no measurement counts."
            );
        }

        $events->dispatch(new CircuitCompleted(
            $driverName,
            $this->circuit,
            new CircuitResult($snapshot->counts),
            $this->taskArn,
        ));
    }
}
