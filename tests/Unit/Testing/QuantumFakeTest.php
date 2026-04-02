<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Results\CircuitResult;
use Aether\Testing\QuantumFake;

// -------------------------------------------------------------------------
// Interface contract
// -------------------------------------------------------------------------

it('implements QuantumDevice', function () {
    $fake = new QuantumFake();

    expect($fake)->toBeInstanceOf(QuantumDevice::class);
});

// -------------------------------------------------------------------------
// executeCircuit()
// -------------------------------------------------------------------------

it('executeCircuit returns a CircuitResult', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('executeCircuit returns non-empty counts', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(3)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result->counts())->not->toBeEmpty();
});

it('executeCircuit returns deterministic 50/50 result based on qubit count', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $result = $fake->executeCircuit($circuit);
    $counts = $result->counts();

    expect($counts)->toHaveKey('00')
        ->and($counts)->toHaveKey('11')
        ->and($counts['00'])->toBe(500)
        ->and($counts['11'])->toBe(500);
});

it('executeCircuit result keys length matches qubit count', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(4)->measure();

    $result = $fake->executeCircuit($circuit);
    $counts = $result->counts();

    foreach (array_keys($counts) as $bitstring) {
        expect(strlen((string) $bitstring))->toBe(4);
    }
});

// -------------------------------------------------------------------------
// generateEntropy()
// -------------------------------------------------------------------------

it('generateEntropy returns a string', function () {
    $fake = new QuantumFake();

    $entropy = $fake->generateEntropy(128);

    expect($entropy)->toBeString();
});

it('generateEntropy returns bytes of correct length for 256 bits', function () {
    $fake = new QuantumFake();

    $entropy = $fake->generateEntropy(256);

    // 256 bits / 8 = 32 bytes
    expect(strlen($entropy))->toBe(32);
});

it('generateEntropy returns bytes of correct length for non-multiple of 8 bits', function () {
    $fake = new QuantumFake();

    // 9 bits → ceil(9/8) = 2 bytes
    $entropy = $fake->generateEntropy(9);

    expect(strlen($entropy))->toBe(2);
});

it('generateEntropy returns deterministic bytes with 0xAA pattern', function () {
    $fake = new QuantumFake();

    $entropy = $fake->generateEntropy(16);

    expect($entropy)->toBe(str_repeat("\xAA", 2));
});

// -------------------------------------------------------------------------
// Recording circuit executions
// -------------------------------------------------------------------------

it('hasExecutedCircuits returns false when no circuits have been run', function () {
    $fake = new QuantumFake();

    expect($fake->hasExecutedCircuits())->toBeFalse();
});

it('hasExecutedCircuits returns true after a circuit is executed', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect($fake->hasExecutedCircuits())->toBeTrue();
});

it('recordedCircuits returns empty array initially', function () {
    $fake = new QuantumFake();

    expect($fake->recordedCircuits())->toBeEmpty();
});

it('recordedCircuits count increments with each execution', function () {
    $fake     = new QuantumFake();
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->executeCircuit($circuit1);
    $fake->executeCircuit($circuit2);

    expect($fake->recordedCircuits())->toHaveCount(2);
});

it('recordedCircuits contains the executed CircuitBuilder instances', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect($fake->recordedCircuits()[0])->toBe($circuit);
});

// -------------------------------------------------------------------------
// Recording entropy generations
// -------------------------------------------------------------------------

it('hasGeneratedEntropy returns false when no entropy has been generated', function () {
    $fake = new QuantumFake();

    expect($fake->hasGeneratedEntropy())->toBeFalse();
});

it('hasGeneratedEntropy returns true after entropy is generated', function () {
    $fake = new QuantumFake();

    $fake->generateEntropy(64);

    expect($fake->hasGeneratedEntropy())->toBeTrue();
});

it('recordedEntropy returns empty array initially', function () {
    $fake = new QuantumFake();

    expect($fake->recordedEntropy())->toBeEmpty();
});

it('recordedEntropy contains each bits value passed to generateEntropy', function () {
    $fake = new QuantumFake();

    $fake->generateEntropy(128);
    $fake->generateEntropy(256);

    expect($fake->recordedEntropy())->toBe([128, 256]);
});

// -------------------------------------------------------------------------
// assertCircuitRan()
// -------------------------------------------------------------------------

it('assertCircuitRan passes after at least one circuit is executed', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->executeCircuit($circuit);

    // Should not throw
    $fake->assertCircuitRan();
    expect(true)->toBeTrue();
});

it('assertCircuitRan fails when no circuits have been executed', function () {
    $fake = new QuantumFake();

    expect(fn () => $fake->assertCircuitRan())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('assertCircuitRan with callback passes when callback returns true for a recorded circuit', function () {
    $fake     = new QuantumFake();
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(3)->measure();

    $fake->executeCircuit($circuit1);
    $fake->executeCircuit($circuit2);

    // Should not throw — circuit with 3 qubits exists
    $fake->assertCircuitRan(fn (CircuitBuilder $c) => $c->qubitCount() === 3);
    expect(true)->toBeTrue();
});

it('assertCircuitRan with callback fails when no recorded circuit matches', function () {
    $fake    = new QuantumFake();
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    // No circuit with 5 qubits was recorded
    expect(fn () => $fake->assertCircuitRan(fn (CircuitBuilder $c) => $c->qubitCount() === 5))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// assertEntropyGenerated()
// -------------------------------------------------------------------------

it('assertEntropyGenerated passes after entropy has been generated', function () {
    $fake = new QuantumFake();

    $fake->generateEntropy(128);

    // Should not throw
    $fake->assertEntropyGenerated();
    expect(true)->toBeTrue();
});

it('assertEntropyGenerated fails when no entropy has been generated', function () {
    $fake = new QuantumFake();

    expect(fn () => $fake->assertEntropyGenerated())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('assertEntropyGenerated with specific bits passes when that bit count was requested', function () {
    $fake = new QuantumFake();

    $fake->generateEntropy(256);

    // Should not throw
    $fake->assertEntropyGenerated(256);
    expect(true)->toBeTrue();
});

it('assertEntropyGenerated with specific bits fails when that bit count was not requested', function () {
    $fake = new QuantumFake();

    $fake->generateEntropy(128);

    expect(fn () => $fake->assertEntropyGenerated(256))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// executeCircuit() — respects shot count
// -------------------------------------------------------------------------

it('respects circuit shot count', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $circuit = (new \Aether\Circuit\CircuitBuilder($fake))->qubits(2)->shots(100)->measure();

    $result = $fake->executeCircuit($circuit);
    $total = array_sum($result->counts());

    expect($total)->toBe(100);
});

// -------------------------------------------------------------------------
// assertCircuitNotRan()
// -------------------------------------------------------------------------

it('assertCircuitNotRan passes when no circuits executed', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $fake->assertCircuitNotRan();
    expect(true)->toBeTrue();
});

it('assertCircuitNotRan fails when circuits were executed', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $circuit = (new \Aether\Circuit\CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeCircuit($circuit);
    $fake->assertCircuitNotRan();
})->throws(\PHPUnit\Framework\ExpectationFailedException::class);

// -------------------------------------------------------------------------
// assertEntropyNotGenerated()
// -------------------------------------------------------------------------

it('assertEntropyNotGenerated passes when no entropy generated', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $fake->assertEntropyNotGenerated();
    expect(true)->toBeTrue();
});

it('assertEntropyNotGenerated fails when entropy was generated', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $fake->generateEntropy(128);
    $fake->assertEntropyNotGenerated();
})->throws(\PHPUnit\Framework\ExpectationFailedException::class);

// -------------------------------------------------------------------------
// assertCircuitRanTimes()
// -------------------------------------------------------------------------

it('assertCircuitRanTimes passes with correct count', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $circuit = (new \Aether\Circuit\CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeCircuit($circuit);
    $fake->executeCircuit($circuit);
    $fake->assertCircuitRanTimes(2);
    expect(true)->toBeTrue();
});

it('assertCircuitRanTimes fails with wrong count', function () {
    $fake = new \Aether\Testing\QuantumFake();
    $fake->assertCircuitRanTimes(1);
})->throws(\PHPUnit\Framework\ExpectationFailedException::class);
