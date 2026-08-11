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
 * Re-dispatches itself with an incremented attempt counter while the task
 * is still in flight, up to `aether.max_poll_attempts`. Once the task
 * completes, fires {@see CircuitCompleted}; a non-successful terminal state
 * raises {@see TaskFailedException}.
 */
class PollQuantumTask implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * The job manages its own retry/backoff loop by re-dispatching itself
     * (see `attempt`/`max_poll_attempts`), so the queue's own retry
     * mechanism is left at 1 to avoid attempt counters diverging.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $taskArn  The task identifier returned by submitCircuit().
     * @param  array{qubits: int, gates: array<int, array<string, mixed>>, shots: int}  $circuit  The original CircuitBuilder::toArray() payload.
     * @param  string|null  $driver  The driver name to resolve, or null for the configured default.
     * @param  int  $attempt  The 1-indexed number of this poll attempt.
     */
    public function __construct(
        public readonly string $taskArn,
        public readonly array $circuit,
        public readonly ?string $driver = null,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue(config('aether.queue'));
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
            $maxAttempts = (int) config('aether.max_poll_attempts', 720);
            $nextAttempt = $this->attempt + 1;

            if ($nextAttempt > $maxAttempts) {
                throw QuantumExecutionException::pollingExhausted($this->taskArn, $this->attempt);
            }

            self::dispatch($this->taskArn, $this->circuit, $this->driver, $nextAttempt)
                ->delay((int) config('aether.poll_interval', 5));

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
