<?php

declare(strict_types=1);

use Aether\Facades\Quantum;
use Aether\Tasks\TaskStatus;
use Aether\Tests\Fixtures\CustomProviderDriver;

// -------------------------------------------------------------------------
// Pluggable Python providers, end-to-end
//
// A custom driver registered through Quantum::extend() pairs with a Python
// provider module declared via the "python_provider" driver-config key. These
// tests run the real PythonBridge against bin/python with the fixture
// provider in tests/Fixtures/polling_provider.py, which resolves a stub
// device — no Braket SDK, no AWS credentials. Skipped locally when no Python
// interpreter exists, but hard-fails under CI so the guarantee cannot
// silently erode (mirroring GateParityTest).
// -------------------------------------------------------------------------

/**
 * Locate a Python interpreter, skipping (or failing under CI) when absent.
 *
 * AETHER_PYTHON_PATH wins when set, so the suite can be pointed at a
 * virtualenv that has the Braket SDK installed.
 */
function customProviderPythonPath(): string
{
    $configured = getenv('AETHER_PYTHON_PATH');
    if (is_string($configured) && $configured !== '' && is_executable($configured)) {
        return $configured;
    }

    $pythonPath = null;
    foreach (['python3', 'python'] as $candidate) {
        $which = shell_exec("which {$candidate} 2>/dev/null");
        if ($which !== null && $which !== '') {
            $pythonPath = trim($which);
            break;
        }
    }

    if ($pythonPath === null) {
        if (getenv('CI') !== false) {
            test()->fail('A Python interpreter is required in CI: the custom provider check must not be skipped.');
        }

        test()->markTestSkipped('No Python interpreter available on this system.');
    }

    return $pythonPath;
}

/**
 * Register the fixture-backed custom driver and return its name.
 */
function registerCustomProviderDriver(string $pythonPath): string
{
    config()->set('aether.python_path', $pythonPath);
    config()->set('aether.drivers.custom', [
        'python_provider' => realpath(__DIR__.'/../Fixtures/polling_provider.py'),
        'synchronous_safe' => true,
    ]);

    Quantum::extend('custom', fn (): CustomProviderDriver => new CustomProviderDriver(
        Quantum::bridge(),
        config('aether.drivers.custom'),
    ));

    return 'custom';
}

it('polls a task through a custom python provider defining check_task', function () {
    $pythonPath = customProviderPythonPath();
    $driver = registerCustomProviderDriver($pythonPath);

    $snapshot = Quantum::driver($driver)->checkTask('custom-task-1');

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['00' => 7, '11' => 3]);
});

it('executes a circuit through a custom python provider resolving a stub device', function () {
    $pythonPath = customProviderPythonPath();

    // circuit.py builds the Braket circuit before handing it to the provider's
    // device, so this path needs the SDK even though the device is a stub.
    $hasBraket = trim((string) shell_exec(
        escapeshellarg($pythonPath)." -c 'import braket' 2>/dev/null && echo yes"
    )) === 'yes';

    if (! $hasBraket) {
        test()->markTestSkipped('The amazon-braket-sdk is not installed for the discovered Python interpreter.');
    }

    $driver = registerCustomProviderDriver($pythonPath);

    $result = Quantum::circuit($driver)->qubits(2)->h(0)->cnot(0, 1)->measure()->run();

    expect($result->counts())->toBe(['00' => 6, '11' => 4]);
});
