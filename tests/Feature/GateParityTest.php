<?php

declare(strict_types=1);

use Aether\Bridge\PythonBridge;
use Aether\Circuit\GateType;

// -------------------------------------------------------------------------
// PHP ↔ Python gate table parity
//
// The PHP side declares every gate's wire shape in the GateType/GateShape
// enums; the Python side declares the same knowledge in common.py's
// GATE_PARAMS table. This test executes bin/python/list_gates.py through the
// real PythonBridge and asserts the two tables are identical, so a gate
// added on one side without the other fails loudly here instead of at
// runtime. Skipped locally when no Python interpreter exists, but hard-fails
// under CI so the guarantee cannot silently erode.
// -------------------------------------------------------------------------

it('keeps the PHP gate metadata in parity with the Python GATE_PARAMS table', function () {
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
            test()->fail('A Python interpreter is required in CI: the gate parity check must not be skipped.');
        }

        test()->markTestSkipped('No Python interpreter available on this system.');
    }

    $bridge = new PythonBridge($pythonPath);

    $result = $bridge->execute('list_gates.py', []);

    expect($result)->toHaveKey('gates');

    $python = $result['gates'];

    $php = collect(GateType::cases())->mapWithKeys(fn (GateType $type): array => [
        $type->value => [
            'qubits' => $type->shape()->qubitKeys(),
            'angles' => $type->shape()->angleKeys(),
        ],
    ])->all();

    ksort($python);
    ksort($php);

    expect(array_keys($python))->toBe(array_keys($php))
        ->and($python)->toBe($php);
});
