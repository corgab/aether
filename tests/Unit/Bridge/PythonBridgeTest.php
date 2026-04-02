<?php declare(strict_types=1);

use Aether\Bridge\PythonBridge;
use Aether\Exceptions\PythonEnvironmentException;
use Aether\Exceptions\QuantumExecutionException;

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
// buildEnvironment()
// -------------------------------------------------------------------------

it('includes only non-null values in environment', function () {
    $bridge = new PythonBridge('python3');

    $env = $bridge->buildEnvironment([
        'region'     => 'us-east-1',
        'bucket'     => null,
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
        'region'                => 'eu-west-1',
        'AWS_ACCESS_KEY_ID'     => 'AKIAIOSFODNN7EXAMPLE',
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
        'region'     => 'ap-southeast-2',
        'bucket'     => 'my-braket-bucket',
        'device_arn' => 'arn:aws:braket:::device/qpu/ionq/ionQdevice',
    ]);

    expect($env)->toBe([
        'AWS_DEFAULT_REGION' => 'ap-southeast-2',
        'AETHER_S3_BUCKET'   => 'my-braket-bucket',
        'AETHER_DEVICE_ARN'  => 'arn:aws:braket:::device/qpu/ionq/ionQdevice',
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
    expect($bridge)->toBeInstanceOf(\Aether\Contracts\PythonExecutor::class);
});
