<?php

declare(strict_types=1);

use Aether\Bridge\PythonBridge;
use Aether\Contracts\PythonExecutor;
use Aether\Exceptions\PythonEnvironmentException;
use Aether\Exceptions\QuantumExecutionException;

/**
 * Create a throwaway executable that stands in for the python interpreter,
 * emitting a fixed stdout/stderr/exit code so execute()'s output-handling
 * branches can be exercised without a real Python environment.
 */
function fakePython(string $shBody): string
{
    $path = tempnam(sys_get_temp_dir(), 'aether_fakepy_');
    file_put_contents($path, "#!/bin/sh\n{$shBody}\n");
    chmod($path, 0o755);

    return $path;
}

// -------------------------------------------------------------------------
// scriptsPath()
// -------------------------------------------------------------------------

it('resolves scripts path to bin/python directory', function () {
    $bridge = new PythonBridge('python3');

    $scriptsPath = $bridge->scriptsPath();

    expect($scriptsPath)->toEndWith('bin/python');
    expect(is_dir($scriptsPath))->toBeTrue();
});

// -------------------------------------------------------------------------
// execute() — invalid python binary → PythonEnvironmentException
// -------------------------------------------------------------------------

it('throws PythonEnvironmentException with invalid python path', function () {
    $bridge = new PythonBridge('/nonexistent/python_binary');

    $bridge->execute('run_circuit.py', ['qubits' => 2]);
})->throws(PythonEnvironmentException::class);

// -------------------------------------------------------------------------
// execute() — nonexistent script → QuantumExecutionException
// -------------------------------------------------------------------------

it('throws QuantumExecutionException with nonexistent script', function () {
    $pythonPath = null;
    foreach (['python3', 'python'] as $candidate) {
        $which = shell_exec("which {$candidate} 2>/dev/null");
        if ($which !== null && $which !== '') {
            $pythonPath = trim($which);
            break;
        }
    }

    if ($pythonPath === null) {
        test()->markTestSkipped('No Python interpreter available on this system.');
    }

    $bridge = new PythonBridge($pythonPath);

    $bridge->execute('nonexistent_script_that_does_not_exist.py', ['qubits' => 2]);
})->throws(QuantumExecutionException::class);

// -------------------------------------------------------------------------
// execute() — output handling (via a fake interpreter)
// -------------------------------------------------------------------------

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/aether_fakepy_*') ?: [] as $tmp) {
        @unlink($tmp);
    }
});

it('returns the decoded array on a successful run', function () {
    $python = fakePython("printf '{\"counts\":{\"00\":7,\"11\":3}}'");

    $result = (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);

    expect($result)->toBe(['counts' => ['00' => 7, '11' => 3]]);
});

it('throws QuantumExecutionException when python exits non-zero', function () {
    $python = fakePython("printf 'kaboom' >&2; exit 3");

    try {
        (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);
        test()->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e->getMessage())->toContain('kaboom');
        expect($e->getCode())->toBe(3);
    }
});

it('throws QuantumExecutionException on invalid JSON output', function () {
    $python = fakePython("printf 'this is not json'");

    expect(fn () => (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]))
        ->toThrow(QuantumExecutionException::class, 'Invalid JSON');
});

it('throws QuantumExecutionException when output is a JSON scalar, not an object', function () {
    $python = fakePython("printf '42'");

    expect(fn () => (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]))
        ->toThrow(QuantumExecutionException::class, 'Expected JSON object');
});

// -------------------------------------------------------------------------
// bitstringToBytes()
// -------------------------------------------------------------------------

it('converts a binary digit string into raw bytes', function () {
    $bridge = new PythonBridge('python3');

    // '01001000' = 0x48 = 'H', '01101001' = 0x69 = 'i'
    expect($bridge->bitstringToBytes('0100100001101001'))->toBe('Hi');
});

// -------------------------------------------------------------------------
// buildEnvironment()
// -------------------------------------------------------------------------

it('includes only non-null values in environment', function () {
    $bridge = new PythonBridge('python3');

    $env = $bridge->buildEnvironment([
        'region' => 'us-east-1',
        'bucket' => null,
        'device_arn' => 'arn:aws:braket:::device/quantum-simulator/amazon/sv1',
    ]);

    expect($env)->toHaveKey('AWS_DEFAULT_REGION');
    expect($env['AWS_DEFAULT_REGION'])->toBe('us-east-1');
    expect($env)->not->toHaveKey('AETHER_S3_BUCKET');
    expect($env)->toHaveKey('AETHER_DEVICE_ARN');
    expect($env['AETHER_DEVICE_ARN'])->toBe('arn:aws:braket:::device/quantum-simulator/amazon/sv1');
});

it('does not include AWS credentials in environment', function () {
    $bridge = new PythonBridge('python3');

    $env = $bridge->buildEnvironment([
        'region' => 'eu-west-1',
        'AWS_ACCESS_KEY_ID' => 'AKIAIOSFODNN7EXAMPLE',
        'AWS_SECRET_ACCESS_KEY' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    ]);

    expect($env)->not->toHaveKey('AWS_ACCESS_KEY_ID');
    expect($env)->not->toHaveKey('AWS_SECRET_ACCESS_KEY');
});

it('returns empty array when config is empty', function () {
    $bridge = new PythonBridge('python3');

    $env = $bridge->buildEnvironment([]);

    expect($env)->toBe([]);
});

it('maps all supported keys in environment', function () {
    $bridge = new PythonBridge('python3');

    $env = $bridge->buildEnvironment([
        'region' => 'ap-southeast-2',
        'bucket' => 'my-braket-bucket',
        'device_arn' => 'arn:aws:braket:::device/qpu/ionq/ionQdevice',
    ]);

    expect($env)->toBe([
        'AWS_DEFAULT_REGION' => 'ap-southeast-2',
        'AETHER_S3_BUCKET' => 'my-braket-bucket',
        'AETHER_DEVICE_ARN' => 'arn:aws:braket:::device/qpu/ionq/ionQdevice',
    ]);
});

it('omits unset keys from environment', function () {
    $bridge = new PythonBridge('python3');

    // Only region provided — bucket and device_arn not present at all.
    $env = $bridge->buildEnvironment(['region' => 'us-west-2']);

    expect($env)->toBe(['AWS_DEFAULT_REGION' => 'us-west-2']);
    expect($env)->not->toHaveKey('AETHER_S3_BUCKET');
    expect($env)->not->toHaveKey('AETHER_DEVICE_ARN');
});

// -------------------------------------------------------------------------
// Contract compliance
// -------------------------------------------------------------------------

it('implements PythonExecutor contract', function () {
    $bridge = new PythonBridge('python3');
    expect($bridge)->toBeInstanceOf(PythonExecutor::class);
});
