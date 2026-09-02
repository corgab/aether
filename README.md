# Aether

Laravel package for quantum computing via AWS Braket and local simulators.

Build quantum circuits, generate hardware-grade entropy, and swap backends with a single config change — all with a fluent, Laravel-native API.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Python 3.8+ with `amazon-braket-sdk`
- A running queue worker, if you use asynchronous execution

## Installation

```bash
composer require corgab/aether
```

Run the install command to publish the config, check Python dependencies, and verify your setup:

```bash
php artisan aether:install
```

This will optionally create a `.aether-venv` virtual environment and install the required Python packages for you.

## Configuration

Publish the config file if you haven't already:

```bash
php artisan vendor:publish --tag=aether-config
```

Add to your `.env`:

```env
AETHER_DRIVER=local
AETHER_PYTHON_PATH=python3
```

For AWS Braket:

```env
AETHER_DRIVER=aws
AWS_DEFAULT_REGION=us-east-1
AETHER_S3_BUCKET=your-bucket
AETHER_DEVICE_ARN=arn:aws:braket:::device/quantum-simulator/amazon/sv1
```

`AETHER_S3_BUCKET` is required by the `aws` driver, together with the region and the device ARN: a missing or empty value throws an `InvalidDriverConfigException` on every call, including a synchronous `->run()` against the SV1 simulator. Braket writes the task results to `s3://<bucket>/results`.

## Usage

### Quantum Circuits

```php
use Aether\Facades\Quantum;

$result = Quantum::circuit()
    ->qubits(2)
    ->h(0)
    ->cnot(0, 1)
    ->measure()
    ->shots(1024)
    ->run();

$result->counts();        // ['00' => 512, '11' => 512]
$result->probabilities(); // ['00' => 0.5, '11' => 0.5]
$result->mostFrequent();  // '00'
```

### Entropy Generation

```php
$entropy = Quantum::entropy();

$bytes = $entropy->generate(256);    // 32 raw bytes
$hex = $entropy->hex(128);           // 32-char hex string
$roll = $entropy->integer(1, 6);     // unbiased die roll (rejection sampling)
```

### Batch Execution

Run several circuits in a single Python process instead of paying the interpreter start-up cost once per circuit. The results come back as a `BatchResult`, ordered like the input, which is arrayable, jsonable, countable and iterable over the individual `CircuitResult` objects.

```php
use Aether\Facades\Quantum;

$batch = Quantum::batch([$circuitA, $circuitB])->run();

foreach ($batch as $index => $result) {
    $result->counts(); // array<string, int>
}

$batch->get(1)->mostFrequent();
$batch[0]->probabilities();
```

* **Validation**: every circuit is validated like a single `->run()` would, so a circuit without qubits or without `measure()` throws `InvalidCircuitException` before anything is executed.
* **Per-circuit shots**: each circuit keeps its own `->shots()`. On AWS the whole batch is submitted at once with one shot count per task; the local simulator does not support that, so with mixed shot counts the circuits run sequentially inside the same Python process.
* **Driver mismatch**: a circuit pinned to another driver (e.g. `Quantum::circuit('aws')`) cannot be run in a batch targeting a different driver — `InvalidCircuitException::batchDriverMismatch` is thrown.
* **QPU safety**: `synchronous_safe` applies to batches too. A batch `->run()` on a driver marked `synchronous_safe: false` throws, exactly like a single `->run()`.
* **Contracts**: batch-capable drivers implement `Aether\Contracts\BatchableDevice`; `Quantum::batch()` on a driver that does not throws `QuantumExecutionException::batchUnsupported`. The core `Aether\Contracts\QuantumDevice` contract is unchanged, so third-party drivers keep working.

### Asynchronous Execution

Real QPU tasks queue for minutes or hours, so a synchronous `->run()` would block the request. Use `->dispatch()` instead: the circuit is submitted by a queued job, polled until it reaches a terminal state, and the result is delivered through an event.

```php
use Aether\Facades\Quantum;

Quantum::circuit('aws')
    ->qubits(2)
    ->h(0)
    ->cnot(0, 1)
    ->measure()
    ->shots(1000)
    ->dispatch();
```

`->queue('quantum')` does the same on a specific queue, and both return Laravel's `PendingDispatch`, so the usual chaining works:

```php
Quantum::circuit('aws')->qubits(1)->h(0)->measure()->dispatch()->onQueue('quantum');
```

Listen for the result:

```php
use Aether\Events\CircuitCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(function (CircuitCompleted $event) {
    $event->result->counts();  // ['00' => 503, '11' => 497]
    $event->taskArn;           // 'arn:aws:braket:...:quantum-task/...'
    $event->driver;            // 'aws'
});
```

Tune the polling in `config/aether.php`:

```env
AETHER_QUEUE=quantum
AETHER_POLL_INTERVAL=5
AETHER_MAX_POLL_ATTEMPTS=720
```

`PollQuantumTask` re-checks the task with Laravel's job `release()`, waiting `AETHER_POLL_INTERVAL` seconds between attempts, so asynchronous AWS execution needs a real queue connection with a running worker (`php artisan queue:work`). The `sync` connection is not supported: there `release()` is a no-op, so polling stops silently after the first non-terminal check — no event, no error. The local driver is unaffected, since its tasks are already terminal on the first poll.

A task that fails or is cancelled throws `TaskFailedException` from the polling job; one that never finishes within `max_poll_attempts` throws `QuantumExecutionException`. Both land in `failed_jobs` with the task ARN in the message, so you can inspect the task in the AWS console. The job declares `$maxExceptions = 1`, so any exception fails it immediately without retries — the re-check loop is driven by `release()`, not by queue retries.

The local simulator supports `->dispatch()` too — it executes immediately and caches the result under a synthetic `local:` task id, so you can develop the full asynchronous flow without touching AWS.

#### Task Persistence

You can optionally record every asynchronously dispatched task into a database table.

```bash
php artisan vendor:publish --tag=aether-migrations
php artisan migrate
```

Set `AETHER_PERSIST_TASKS=true` in your `.env`.

When enabled, Aether inserts a row into `quantum_tasks` containing the circuit, shots, and driver when a task is dispatched, and updates its `status` and `counts` as the polling job progresses. The `status` always mirrors the backend's real state. Polling problems (like exhaustion or malformed responses) are logged in `error` and `failed_at`.

Since persistence is strictly best-effort, a database failure never affects queue behaviour or prevents the `CircuitCompleted` event from being emitted.

```php
use Aether\Models\QuantumTask;
use Aether\Tasks\TaskStatus;

$runningTasks = QuantumTask::where('status', TaskStatus::Running)->get();
```

### Switching Drivers

```php
// Use the default driver
Quantum::circuit()->qubits(1)->h(0)->measure()->run();

// Use a specific driver
Quantum::circuit('aws')->qubits(1)->h(0)->measure()->run();
```

### Custom Drivers

```php
use Aether\Facades\Quantum;

Quantum::extend('my-driver', fn () => new MyQuantumDriver());

Quantum::driver('my-driver')->executeCircuit($circuit);
```

## Available Gates

| Method | Description |
|--------|-------------|
| `h($qubit)` | Hadamard |
| `x($qubit)` | Pauli-X (NOT) |
| `y($qubit)` | Pauli-Y |
| `z($qubit)` | Pauli-Z |
| `i($qubit)` | Identity |
| `s($qubit)` | Phase-S |
| `si($qubit)` | Phase-S† (adjoint S) |
| `t($qubit)` | Phase-T |
| `ti($qubit)` | Phase-T† (adjoint T) |
| `rx($qubit, $angle)` | Rotation around the X-axis (`float` radians or `Angle`) |
| `ry($qubit, $angle)` | Rotation around the Y-axis (`float` radians or `Angle`) |
| `rz($qubit, $angle)` | Rotation around the Z-axis (`float` radians or `Angle`) |
| `phaseshift($qubit, $angle)` | Phase shift (`float` radians or `Angle`) |
| `u($qubit, $theta, $phi, $lambda)` | Universal single-qubit rotation |
| `cnot($control, $target)` | Controlled-NOT |
| `cz($control, $target)` | Controlled-Z |
| `cy($control, $target)` | Controlled-Y |
| `crx($control, $target, $angle)` | Controlled-RX (`float` radians or `Angle`) |
| `cry($control, $target, $angle)` | Controlled-RY (`float` radians or `Angle`) |
| `crz($control, $target, $angle)` | Controlled-RZ (`float` radians or `Angle`) |
| `cphaseshift($control, $target, $angle)` | Controlled-PhaseShift (`float` radians or `Angle`) |
| `swap($qubit0, $qubit1)` | SWAP |
| `iswap($qubit0, $qubit1)` | iSWAP |
| `xx($qubit0, $qubit1, $angle)` | Ising XX coupling |
| `yy($qubit0, $qubit1, $angle)` | Ising YY coupling |
| `zz($qubit0, $qubit1, $angle)` | Ising ZZ coupling |
| `ccnot($control0, $control1, $target)` | Toffoli (CCNOT) |
| `cswap($control, $qubit0, $qubit1)` | Controlled-SWAP (Fredkin) |
| `measure($targets)` | Measurement (null = all qubits) |

### Circuit Composition

Reusable sub-circuits can be appended to a circuit with `append()`, passing either another builder or a closure that receives an isolated builder with the same qubit count. Measurement gates in the fragment are dropped, so the parent circuit decides where to measure:

```php
$bell = Quantum::circuit()->qubits(2)->h(0)->cnot(0, 1);

$result = Quantum::circuit()
    ->qubits(2)
    ->append($bell)
    ->append(fn ($c) => $c->rz(0, M_PI / 4))
    ->measure()
    ->run();
```

Appending a fragment that requires more qubits than the circuit has throws an `InvalidCircuitException`.

### Adding a Gate

Gate knowledge lives in a single metadata layer on each side of the bridge: the `GateType` / `GateShape` enums in `src/Circuit/` (PHP) and the `GATE_PARAMS` table in `bin/python/common.py` (Python). Adding a gate touches exactly five places:

1. A `GateType` case (and, for a new parameter shape, a `GateShape` case)
2. A static factory on `Gate`
3. A fluent method on `CircuitBuilder` (a one-liner delegating to `push()`)
4. A `GATE_PARAMS` row in `bin/python/common.py`
5. A row in the gate table above

Everything else is derived from the metadata. The test suite enforces completeness: a `GateType` case without a factory, fluent method, or wire-contract dataset entry fails the Unit suite, and a PHP/Python mismatch fails `tests/Feature/GateParityTest.php`, which compares the two tables through the real Python bridge.

## Events

Aether dispatches events at each execution choke point, so application code can react without coupling to a specific driver call.

| Event | When | Payload |
|-------|------|---------|
| `CircuitExecuted` | A circuit finishes executing synchronously (`->run()`) | `driver` (`string`), `circuit` (the `toArray()` definition), `result` (`CircuitResult`) |
| `EntropyGenerated` | A device generates entropy (`EntropyGenerator::generate()`/`hex()`/`integer()`) | `driver` (`string`), `bits` (`int`, the requested bit count) |
| `CircuitCompleted` | An asynchronously dispatched task (`->dispatch()`) reaches a terminal state | `driver` (`string`), `circuit`, `result` (`CircuitResult`), `taskArn` (`?string`) — see [Asynchronous Execution](#asynchronous-execution) |

`EntropyGenerated` deliberately never carries the generated bytes: entropy typically feeds tokens, keys or nonces, so exposing the value to every registered listener would defeat the point of keeping it secret. Capture `EntropyGenerator::generate()`/`hex()`/`integer()`'s return value directly if you need the material itself.

None of these events fire when execution fails — a malformed response or a driver exception is raised before the event is dispatched.

```php
use Aether\Events\CircuitExecuted;
use Aether\Events\EntropyGenerated;
use Illuminate\Support\Facades\Event;

Event::listen(function (CircuitExecuted $event) {
    $event->result->counts();  // ['00' => 503, '11' => 497]
    $event->driver;            // 'local'
});

Event::listen(function (EntropyGenerated $event) {
    $event->bits;    // 256
    $event->driver;  // 'aws'
});
```

`Quantum::fake()` dispatches `CircuitExecuted` and `EntropyGenerated` too, mirroring the real drivers, so `Event::fake()` assertions on application code keep working the same way whether or not the backend itself is faked.

## Testing

Aether provides a `Quantum::fake()` method that works like `Http::fake()` or `Mail::fake()`:

```php
use Aether\Facades\Quantum;

$fake = Quantum::fake();

// Run your application code...

$fake->assertCircuitRan();
$fake->assertCircuitRan(fn ($circuit) => $circuit->qubitCount() === 2);
$fake->assertEntropyGenerated(256);
```

Batch executions are recorded as well. Every circuit in a batch also counts as an executed circuit, so `assertCircuitRan()` sees it:

```php
$fake->assertBatchRan();
$fake->assertBatchRan(fn (array $circuits) => count($circuits) === 2);
$fake->assertBatchNotRan();
$fake->assertBatchRanTimes(1);
```

Asynchronously dispatched circuits are recorded separately:

```php
$fake->assertCircuitDispatched();
$fake->assertCircuitDispatched(fn ($circuit) => $circuit->shotCount() === 1000);
$fake->assertCircuitDispatchedTimes(2);
$fake->assertCircuitNotDispatched();
```

Stub what the fake returns:

```php
$fake->respondWithCounts(['00' => 1000]);          // measurement counts
$fake->respondWithTaskStatus(TaskStatus::Running); // simulate a task still in flight
```

### Running the Test Suite

```bash
composer test
```

## Synchronous Safety

When using real QPU hardware, requests can take minutes. Set `synchronous_safe` to `false` in your driver config to prevent accidental synchronous calls that would block your HTTP request:

```php
// config/aether.php
'aws' => [
    'synchronous_safe' => false,
    // ...
],
```

This will throw a `QuantumExecutionException` on direct calls to `->run()`, forcing you to use `->dispatch()` instead. Asynchronous submission is never blocked by this flag — that is the path the flag is steering you toward.

## License

MIT
