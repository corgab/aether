# Aether

Laravel package for quantum computing via AWS Braket and local simulators.

Build quantum circuits, generate hardware-grade entropy, and swap backends with a single config change — all with a fluent, Laravel-native API.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Python 3.8+ with `amazon-braket-sdk`

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
| `cnot($control, $target)` | Controlled-NOT |
| `measure($targets)` | Measurement (null = all qubits) |

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

This will throw a `QuantumExecutionException` on direct calls, forcing you to use queued jobs instead.

## License

MIT
