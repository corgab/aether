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
- **PythonBridge** only passes non-null env vars to preserve boto3 credential chain (IAM Roles).
- **QPU safety:** Drivers with `synchronous_safe: false` throw on `->run()` to prevent HTTP timeouts.
- **EntropyGenerator::integer()** uses rejection sampling on a 256-bit batch buffer — never modulo.
- **Task failure reasons:** `check_task()` forwards Braket's `failureReason` as an optional `error` key; `TaskSnapshot->error` carries it and `TaskFailedException::forTask()` includes it in the message.

## Config

Published to `config/aether.php`. Key settings: `default` (driver name), `python_path` (Python executable), `drivers` (per-driver config with `synchronous_safe` flag).

## Testing

`Quantum::fake()` replaces the manager with `QuantumFake` — same pattern as `Http::fake()`. Provides `assertCircuitRan()` and `assertEntropyGenerated()`.
