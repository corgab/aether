# Aether

Laravel package for quantum computing via AWS Braket and local simulators.

Build quantum circuits, generate hardware-grade entropy, and swap backends with a single config change — all with a fluent, Laravel-native API.

## Requirements

- PHP 8.3+
- Laravel 13
- Python 3.11+ with `amazon-braket-sdk` (CI runs 3.11 and 3.12)
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

$result->counts();          // ['00' => 512, '11' => 512]
$result->probabilities();   // ['00' => 0.5, '11' => 0.5]
$result->mostFrequent();    // '00'

$result->count('00');       // 512 — measurement count for a single bitstring
$result->count();           // 2   — number of distinct outcomes (Countable)
$result->probability('00'); // 0.5 — probability of a single bitstring
$result->shots();           // 1024 — total measurements
$result->outcomes();        // ['00', '11'] — bitstrings sorted by count, descending
```

`CircuitBuilder` exposes read-only introspection on the circuit being built:

```php
$builder = Quantum::circuit()->qubits(2)->h(0)->cnot(0, 1)->measure();

$builder->gateCount(); // 2 — number of gates, excluding measurement
$builder->depth();     // 2 — number of sequential layers, excluding measurement
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
AETHER_MAX_POLL_EXCEPTIONS=5
```

`PollQuantumTask` re-checks the task with Laravel's job `release()`, waiting `AETHER_POLL_INTERVAL` seconds between attempts, so asynchronous AWS execution needs a real queue connection with a running worker (`php artisan queue:work`). The `sync` connection is not supported: there `release()` is a no-op, so polling stops silently after the first non-terminal check — no event, no error. The local driver is unaffected, since its tasks are already terminal on the first poll.

A task that fails or is cancelled throws `TaskFailedException` from the polling job; one that never finishes within `max_poll_attempts` throws `QuantumExecutionException`. Both fail the job immediately — the re-check loop is driven by `release()`, not by queue retries, so these deliberate outcomes never get another attempt. A transient error instead — a failed `check.py` run, an AWS throttle, a cache hiccup — is left for the worker to retry after `AETHER_POLL_INTERVAL` seconds, up to `AETHER_MAX_POLL_EXCEPTIONS` times (default 5), before the job fails for good. Configuration or environment errors (a missing driver key, a missing Python binary or dependency, an unregistered driver) always fail immediately, since retrying them can never succeed. Every failure lands in `failed_jobs` with the task ARN or error in the message, so you can inspect the task in the AWS console; when [task persistence](#task-persistence) is enabled, the failure is also recorded on the task's `quantum_tasks` row.

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

### Custom Providers

A custom driver usually needs a matching backend on the Python side. Instead of forking the `bin/python` scripts, declare a **provider**: a plain Python module that resolves the device the scripts run circuits on. The scripts pick it from the `python_provider` key of the driver's config — either a filesystem path to a `.py` file or an importable module name — falling back to the built-in providers for the `local` and `aws` drivers.

A provider module may define four module-level hooks; only the first is required:

| Hook | Required | Purpose |
|------|----------|---------|
| `resolve_device(config) -> Device` | yes | Return a Braket-compatible device: `.run(circuit, shots=..., **opts)` returning a task with `.id` and `.result()` (whose result exposes `measurement_counts`). Raise `ValueError` with a human-readable message on bad config. |
| `run_options(config) -> dict` | no | Extra kwargs merged into every `device.run()` call (the aws provider returns the S3 destination folder here). Defaults to `{}`. |
| `run_batch(device, circuits, shots_list, config) -> list[Result]` | no | Full control over batch execution. Without it, uniform shot counts go through one `device.run_batch()` call and mixed shot counts run sequentially. |
| `check_task(task_id, config) -> dict` | no | Return `{"status": "<CREATED\|QUEUED\|RUNNING\|COMPLETED\|FAILED\|CANCELLED>"}`, plus `"counts"` when `COMPLETED`. Without it, task polling fails with `Driver '<name>' does not support task polling.` |

`config` is the driver's config array from `config/aether.php`, passed through the JSON payload — providers should read their settings from it, **not** from environment variables. A minimal provider:

```python
# app/quantum/ionq_provider.py
def resolve_device(config):
    from braket.aws import AwsDevice

    return AwsDevice(config["device_arn"])
```

Wire it up with a driver registered through `Quantum::extend()` — `Quantum::bridge()` hands you the same configured `PythonBridge` the built-in drivers use:

```php
// config/aether.php
'drivers' => [
    'ionq' => [
        'device_arn' => 'arn:aws:braket:us-east-1::device/qpu/ionq/Aria-1',
        'python_provider' => base_path('app/quantum/ionq_provider.py'),
        'synchronous_safe' => false,
    ],
],
```

```php
use Aether\Facades\Quantum;

Quantum::extend('ionq', fn () => new IonqDriver(
    Quantum::bridge(),
    config('aether.drivers.ionq'),
));
```

where `IonqDriver` extends `Aether\Drivers\AbstractQuantumDriver` and returns `'ionq'` from `driverName()` — the base class already implements circuit execution, batching, and entropy generation on top of the scripts.

> **Trust model.** `python_provider` executes arbitrary Python inside the subprocess — it is exactly as powerful as `python_path` itself. Treat it as trusted code: set it only from configuration you control, and never derive it from user input.

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
| `CircuitExecuted` | A circuit finishes executing synchronously (`->run()`, or once per circuit of a `Quantum::batch()->run()`) | `driver` (`string`), `circuit` (the `toArray()` definition), `result` (`CircuitResult`) |
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

`Quantum::fake()` dispatches `CircuitExecuted` and `EntropyGenerated` too, mirroring the real drivers, so `Event::fake()` assertions on application code keep working the same way whether or not the backend itself is faked. `CircuitExecuted` fires only for synchronous execution (`->run()` and `Quantum::batch()->run()`): a local `->dispatch()` runs the simulator inline but announces itself through `CircuitCompleted` alone, like the `aws` driver.

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

Stub what the fake returns, the same way `Http::fake()` accepts stubbed responses:

```php
// A canned counts array, returned by every executed circuit
$fake = Quantum::fake(['00' => 700, '11' => 324]);

// Or a canned CircuitResult, built with QuantumFake::result()
$fake = Quantum::fake(QuantumFake::result(['00' => 700, '11' => 324]));

// A closure evaluated per circuit — branch on the CircuitBuilder about to run
$fake = Quantum::fake(function (CircuitBuilder $circuit) {
    return $circuit->qubitCount() === 2 ? ['00' => 1000] : null; // null falls through to the default
});

// An ordered sequence, one result per call — throws once exhausted, unless whenEmpty() is set
$fake = Quantum::fake(
    QuantumFake::sequence([
        ['0' => 10],
        ['1' => 10],
    ])->whenEmpty(['0' => 5, '1' => 5])
);
```

All forms above can also be set after `fake()` (or changed later) with `respondWith()`, and `respondWithCounts(array $counts)` remains available as a shorthand for the plain counts-array form. Calling `checkTask()` for an asynchronously submitted circuit honours the same stub: the result is resolved on the first successful poll and kept for that task, so polling it repeatedly consumes a single sequence entry, like a real completed task.

Stubbed counts must be shaped like real measurement counts — bitstring keys ("00", "1", ...) and non-negative integer values — or `Quantum::fake()` / `respondWith()` throw an `InvalidArgumentException` immediately. An empty array is accepted, so the empty-result branch (`mostFrequent()` unavailable, zero shots) can be exercised too.

Entropy can be stubbed independently of circuit results:

```php
$fake->respondEntropyWith("\xFF");                    // fixed bytes, tiled to whatever length each call needs
$fake->respondEntropyWith(QuantumFake::hex('ff00'));   // fixed bytes from a hex string
$fake->respondEntropyWith(fn (int $bits) => null);     // closure by bit count; null falls through to the default
```

Calling `Quantum::fake()` with no arguments keeps the original deterministic behaviour unchanged: a 50/50 split of `0...0`/`1...1` counts, and an incrementing byte counter for entropy.

```php
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

## Qubit Ceiling

The local simulator keeps a full statevector in memory, and that memory doubles with every additional qubit. To guard against accidentally exhausting host memory, the `local` driver enforces a `max_qubits` ceiling (default `25`, roughly 512 MB) on every `->run()`, `->dispatch()`, and `Quantum::batch()` call:

```php
// config/aether.php
'local' => [
    'max_qubits' => env('AETHER_MAX_QUBITS', 25),
    // ...
],
```

A circuit that requests more qubits than the ceiling throws an `InvalidCircuitException` before any Python subprocess is spawned. Raise `AETHER_MAX_QUBITS` if your host has memory to spare, or set it to `null` (or leave `AETHER_MAX_QUBITS=` empty) to remove the ceiling entirely. The `aws` driver has no ceiling by default — Braket enforces its own per-device qubit limits — but a `max_qubits` you configure for it is enforced on `->run()`, `->dispatch()` and `Quantum::batch()` alike.

## Cost Estimation

The `aws` driver can estimate the cost of a circuit before it runs, from configured pricing — no AWS Pricing API call is made:

```php
$estimate = Quantum::circuit('aws')
    ->qubits(2)
    ->h(0)
    ->cnot(0, 1)
    ->measure()
    ->shots(1000)
    ->estimateCost();

$estimate->amount;     // 0.65
$estimate->currency;   // 'USD'
$estimate->shots;      // 1000
$estimate->breakdown;  // ['per_task' => 0.30, 'per_shot' => 0.35]
(string) $estimate;    // '0.65 USD'
```

The rates live in `config/aether.php` and mirror AWS Braket QPU list prices (task + shot) at the time of writing — **verify against current AWS pricing** before relying on them for budgeting, and override via env vars without a package release:

```env
AETHER_AWS_PRICE_PER_TASK=0.30
AETHER_AWS_PRICE_PER_SHOT=0.00035
```

Managed simulators (e.g. SV1) bill per-minute instead, so treat simulator estimates as a rough proxy rather than an exact figure. `estimateCost()` is only available on drivers implementing `EstimatesCost`; calling it on the `local` driver (which is free) throws a `QuantumExecutionException`. `Quantum::fake()` implements the contract too — every estimate is free by default, and `$fake->respondCostWith($estimate)` (a `CostEstimate` or a `fn (int $shots, int $tasks): CostEstimate` closure) stubs a specific one, so budgeting code stays testable.

Set `AETHER_AWS_MAX_COST` (or `max_cost_per_run` in config) to reject a circuit or batch whose estimated cost exceeds it, before any AWS call:

```php
// config/aether.php
'aws' => [
    'max_cost_per_run' => env('AETHER_AWS_MAX_COST', null),
    // ...
],
```

The guard runs on `->run()`, `->dispatch()`, and `Quantum::batch()` (against the batch's total estimated cost — it bounds what one call can spend). It throws an `InvalidCircuitException`. `null` (the default) or an empty `AETHER_AWS_MAX_COST=` means unlimited — existing configs keep working unchanged. A ceiling configured without `pricing` rates throws an `InvalidDriverConfigException` instead of silently never tripping.

## License

MIT
