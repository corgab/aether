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

it('unwraps a JSON error object on stderr to its bare message', function () {
    $python = fakePython('printf \'{"error":"qubit index out of range"}\' >&2; exit 1');

    try {
        (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);
        test()->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e->getMessage())->toContain('qubit index out of range');
        expect($e->getMessage())->not->toContain('{"error"');
    }
});

it('falls back to raw stderr when it is not valid JSON', function () {
    $python = fakePython("printf 'not json at all' >&2; exit 1");

    try {
        (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);
        test()->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e->getMessage())->toContain('not json at all');
    }
});

it('falls back to raw stderr when JSON is valid but has no error key', function () {
    $python = fakePython('printf \'{"detail":"nope"}\' >&2; exit 1');

    try {
        (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);
        test()->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e->getMessage())->toContain('{"detail":"nope"}');
    }
});

// -------------------------------------------------------------------------
// execute() — process timeout
// -------------------------------------------------------------------------

it('throws QuantumExecutionException (not PythonEnvironmentException) when the process times out', function () {
    $python = fakePython('sleep 2');

    try {
        (new PythonBridge($python, timeout: 1))->execute('circuit.py', ['qubits' => 1]);
        test()->fail('Expected QuantumExecutionException was not thrown.');
    } catch (QuantumExecutionException $e) {
        expect($e->getMessage())->toContain('timed out');
    }
});

it('reads the configured timeout and applies it to the process', function () {
    $bridge = new PythonBridge('python3', timeout: 42);

    $reflection = new ReflectionProperty($bridge, 'timeout');

    expect($reflection->getValue($bridge))->toBe(42);
});

it('defaults the timeout to 300 seconds when not provided', function () {
    $bridge = new PythonBridge('python3');

    $reflection = new ReflectionProperty($bridge, 'timeout');

    expect($reflection->getValue($bridge))->toBe(300);
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
// execute() — environment
// -------------------------------------------------------------------------

it('lets the child process inherit the parent environment untouched', function () {
    // boto3 resolves credentials from the environment (AWS_PROFILE, AWS_*,
    // container/IAM metadata hints), so the bridge must forward the parent
    // environment as-is rather than curating its own variable set.
    $_ENV['AETHER_TEST_MARKER'] = 'inherited';

    try {
        $python = fakePython('printf \'{"marker":"%s"}\' "$AETHER_TEST_MARKER"');

        $result = (new PythonBridge($python))->execute('circuit.py', ['qubits' => 1]);
    } finally {
        unset($_ENV['AETHER_TEST_MARKER']);
    }

    expect($result)->toBe(['marker' => 'inherited']);
});

// -------------------------------------------------------------------------
// Contract compliance
// -------------------------------------------------------------------------

it('implements PythonExecutor contract', function () {
    $bridge = new PythonBridge('python3');
    expect($bridge)->toBeInstanceOf(PythonExecutor::class);
});
