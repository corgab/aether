# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is Aether

A Laravel package that bridges Laravel with Quantum Computing via AWS Braket and local simulators. Provides a fluent PHP API over Python-based quantum execution.

## Commands

```bash
# Run all tests
./vendor/bin/pest

# Run a specific test file
./vendor/bin/pest tests/Unit/Circuit/GateTest.php

# Run a test suite
./vendor/bin/pest --testsuite Unit
./vendor/bin/pest --testsuite Feature

# Run a single test by name
./vendor/bin/pest --filter "it generates entropy"

# Install dependencies
composer install
```

## Architecture

```
Quantum (Facade)
  → QuantumManager (multi-driver Manager pattern, like Cache/Session)
    → QuantumDevice (contract in Contracts/)
      → LocalSimulatorDriver | AwsBraketDriver (in Drivers/)
        → PythonBridge (Symfony Process, JSON stdin/stdout)
          → bin/python/*.py (Braket SDK)
            → CircuitResult (Arrayable, Jsonable)
```

**Key flow:** `Quantum::circuit()->qubits(2)->h(0)->cnot(0,1)->measure()->run()` builds a `CircuitBuilder`, which serializes to JSON, passes to a Python script via `PythonBridge`, executes on Braket, and returns a `CircuitResult`.

**Driver switching:** `Quantum::driver('aws')->circuit()...` like `Cache::store('redis')`. Default driver set via `AETHER_DRIVER` env var.

## Conventions

- **PSR-12** strict, `declare(strict_types=1)` in every PHP file
- **PHP 8.3+** features: readonly properties, named arguments, match expressions
- **Laravel style naming:** Driver files use `*Driver` suffix (`LocalSimulatorDriver`, `AwsBraketDriver`). Contracts use semantic names without `Contract` suffix (`Contracts\QuantumDevice`).
- **Tests use Pest PHP**, not raw PHPUnit classes. Use `it()` / `test()` with `expect()`.
- **Python scripts** live in `bin/python/`, not `resources/`. Each script is self-contained (reads JSON stdin, writes JSON stdout).
- **Exceptions** all extend `AetherException` with static factory methods (`::fromPythonError()`, `::forDriver()`, etc.)
- **Driver config is typed:** `AbstractQuantumDriver` builds a `Config\DriverConfig` (`AwsDriverConfig` for aws) once in its constructor; invalid values throw `InvalidDriverConfigException` there, blank means default. Read options via `$this->config->maxQubits` / `->get('key')`, never `$this->config['key']`. The raw array still goes to Python as `driver_config`.
- **PythonBridge** only passes non-null env vars to preserve boto3 credential chain (IAM Roles).
- **QPU safety:** synchronous_safe is tri-state; null (default) refuses ->run() when device_arn contains device/qpu/, true allows, false refuses. Async paths are never blocked.
- **EntropyGenerator::integer()** uses rejection sampling on a 256-bit batch buffer — never modulo.
- **No double submission:** SubmitQuantumCircuit retries only before submitCircuit() returns; a post-submission failure fails the job (or rethrows when not under a worker) instead of letting a retryable exception escape, so a queued retry can never create a second billable task.
- **Ceilings cover entropy:** generateEntropy() describes its circuit as a CircuitBuilder and runs validateCircuits(), so max_qubits and max_cost_per_run apply to Quantum::entropy() too.
- **Task persistence goes through `Tasks\QuantumTaskRecorder`:** both jobs call `recordSubmission()` / `recordProgress()`; the recorder alone checks `aether.persist_tasks` and reports-and-swallows database failures, so a job never repeats that guard or try/catch.
- **Entropy strength is the device's:** only a QPU yields genuinely random bits; the local and managed simulators are pseudorandom. Docblocks and README must never call simulator entropy cryptographically strong.
- **Both outcomes have an event:** `PollQuantumTask` dispatches `CircuitCompleted` on success and `CircuitFailed` (driver, circuit, task ARN, last status, reason) right before failing the job. Both belong to the job: `QuantumFake` only reports statuses, so a job run against it dispatches each event once.
- **Entropy is requested in whole bytes:** AbstractQuantumDriver rounds the bit count up to the next multiple of 8, so every byte returned by EntropyGenerator::generate() is fully measured rather than zero-padded; PythonBridge::bitstringToBytes() rejects bit strings whose length is not a multiple of 8.
- **Polling resilience:** PollQuantumTask lets transient checkTask() errors propagate (retried by the worker with poll_interval backoff, aether.max_poll_exceptions being a lifetime total per job); driver resolution/config/environment errors, malformed check.py responses and terminal task states fail the job at once via FailsWithoutRetry.
- **Local async results:** `LocalSimulatorDriver` caches them in `drivers.local.cache_store` (default store when null) and refuses the process-local array store when the submission job runs on a non-sync connection, unless the store is named explicitly; an unresolvable or null store is refused at dispatch time.

## Config

Published to `config/aether.php`. Key settings: `default` (driver name), `python_path` (Python executable), `drivers` (per-driver config with `synchronous_safe` flag, and, for `local`, `cache_store` — the cache store holding asynchronous results, defaulting to the app's default store).

Package-level settings are read through `Config\AetherConfig` (a container singleton), never via `config('aether.*')` directly: it owns every top-level default (`DEFAULT_DRIVER = 'local'`, poll interval, attempts, local task TTL...) and returns typed values. Jobs get it by method injection in `handle()`; constructors, `tries()` and drivers resolve it with `app(AetherConfig::class)`. Per-driver options (`aether.drivers.*`) are the driver's own business.

## Testing

`Quantum::fake()` replaces the manager with `QuantumFake` — same pattern as `Http::fake()`. Provides `assertCircuitRan()` and `assertEntropyGenerated()`.
