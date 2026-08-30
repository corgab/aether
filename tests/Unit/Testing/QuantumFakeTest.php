<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Tasks\TaskStatus;
use Aether\Testing\QuantumFake;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;

// -------------------------------------------------------------------------
// Interface contract
// -------------------------------------------------------------------------

it('implements QuantumDevice', function () {
    $fake = new QuantumFake;

    expect($fake)->toBeInstanceOf(QuantumDevice::class);
});

// -------------------------------------------------------------------------
// executeCircuit()
// -------------------------------------------------------------------------

it('executeCircuit returns a CircuitResult', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result)->toBeInstanceOf(CircuitResult::class);
});

it('executeCircuit returns non-empty counts', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(3)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result->counts())->not->toBeEmpty();
});

it('executeCircuit returns deterministic 50/50 result based on qubit count', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $result = $fake->executeCircuit($circuit);
    $counts = $result->counts();

    expect($counts)->toHaveKey('00')
        ->and($counts)->toHaveKey('11')
        ->and($counts['00'])->toBe(500)
        ->and($counts['11'])->toBe(500);
});

it('executeCircuit result keys length matches qubit count', function () {
    $fake = new QuantumFake;
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
    $fake = new QuantumFake;

    $entropy = $fake->generateEntropy(128);

    expect($entropy)->toBeString();
});

it('generateEntropy returns bytes of correct length for 256 bits', function () {
    $fake = new QuantumFake;

    $entropy = $fake->generateEntropy(256);

    // 256 bits / 8 = 32 bytes
    expect(strlen($entropy))->toBe(32);
});

it('generateEntropy returns bytes of correct length for non-multiple of 8 bits', function () {
    $fake = new QuantumFake;

    // 9 bits → ceil(9/8) = 2 bytes
    $entropy = $fake->generateEntropy(9);

    expect(strlen($entropy))->toBe(2);
});

it('generateEntropy returns a deterministic counter sequence', function () {
    $fake = new QuantumFake;

    $entropy = $fake->generateEntropy(16);

    expect($entropy)->toBe("\x00\x01");
});

// -------------------------------------------------------------------------
// Recording circuit executions
// -------------------------------------------------------------------------

it('hasExecutedCircuits returns false when no circuits have been run', function () {
    $fake = new QuantumFake;

    expect($fake->hasExecutedCircuits())->toBeFalse();
});

it('hasExecutedCircuits returns true after a circuit is executed', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect($fake->hasExecutedCircuits())->toBeTrue();
});

it('recordedCircuits returns empty array initially', function () {
    $fake = new QuantumFake;

    expect($fake->recordedCircuits())->toBeEmpty();
});

it('recordedCircuits count increments with each execution', function () {
    $fake = new QuantumFake;
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->executeCircuit($circuit1);
    $fake->executeCircuit($circuit2);

    expect($fake->recordedCircuits())->toHaveCount(2);
});

it('recordedCircuits contains the executed CircuitBuilder instances', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect($fake->recordedCircuits()[0])->toBe($circuit);
});

// -------------------------------------------------------------------------
// Recording entropy generations
// -------------------------------------------------------------------------

it('hasGeneratedEntropy returns false when no entropy has been generated', function () {
    $fake = new QuantumFake;

    expect($fake->hasGeneratedEntropy())->toBeFalse();
});

it('hasGeneratedEntropy returns true after entropy is generated', function () {
    $fake = new QuantumFake;

    $fake->generateEntropy(64);

    expect($fake->hasGeneratedEntropy())->toBeTrue();
});

it('recordedEntropy returns empty array initially', function () {
    $fake = new QuantumFake;

    expect($fake->recordedEntropy())->toBeEmpty();
});

it('recordedEntropy contains each bits value passed to generateEntropy', function () {
    $fake = new QuantumFake;

    $fake->generateEntropy(128);
    $fake->generateEntropy(256);

    expect($fake->recordedEntropy())->toBe([128, 256]);
});

// -------------------------------------------------------------------------
// assertCircuitRan()
// -------------------------------------------------------------------------

it('assertCircuitRan passes after at least one circuit is executed', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->executeCircuit($circuit);

    // Should not throw
    $fake->assertCircuitRan();
    expect(true)->toBeTrue();
});

it('assertCircuitRan fails when no circuits have been executed', function () {
    $fake = new QuantumFake;

    expect(fn () => $fake->assertCircuitRan())
        ->toThrow(AssertionFailedError::class);
});

it('assertCircuitRan with callback passes when callback returns true for a recorded circuit', function () {
    $fake = new QuantumFake;
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(3)->measure();

    $fake->executeCircuit($circuit1);
    $fake->executeCircuit($circuit2);

    // Should not throw — circuit with 3 qubits exists
    $fake->assertCircuitRan(fn (CircuitBuilder $c) => $c->qubitCount() === 3);
    expect(true)->toBeTrue();
});

it('assertCircuitRan with callback fails when no recorded circuit matches', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    // No circuit with 5 qubits was recorded
    expect(fn () => $fake->assertCircuitRan(fn (CircuitBuilder $c) => $c->qubitCount() === 5))
        ->toThrow(AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// assertEntropyGenerated()
// -------------------------------------------------------------------------

it('assertEntropyGenerated passes after entropy has been generated', function () {
    $fake = new QuantumFake;

    $fake->generateEntropy(128);

    // Should not throw
    $fake->assertEntropyGenerated();
    expect(true)->toBeTrue();
});

it('assertEntropyGenerated fails when no entropy has been generated', function () {
    $fake = new QuantumFake;

    expect(fn () => $fake->assertEntropyGenerated())
        ->toThrow(AssertionFailedError::class);
});

it('assertEntropyGenerated with specific bits passes when that bit count was requested', function () {
    $fake = new QuantumFake;

    $fake->generateEntropy(256);

    // Should not throw
    $fake->assertEntropyGenerated(256);
    expect(true)->toBeTrue();
});

it('assertEntropyGenerated with specific bits fails when that bit count was not requested', function () {
    $fake = new QuantumFake;

    $fake->generateEntropy(128);

    expect(fn () => $fake->assertEntropyGenerated(256))
        ->toThrow(AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// executeCircuit() — respects shot count
// -------------------------------------------------------------------------

it('respects circuit shot count', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->shots(100)->measure();

    $result = $fake->executeCircuit($circuit);
    $total = array_sum($result->counts());

    expect($total)->toBe(100);
});

// -------------------------------------------------------------------------
// assertCircuitNotRan()
// -------------------------------------------------------------------------

it('assertCircuitNotRan passes when no circuits executed', function () {
    $fake = new QuantumFake;
    $fake->assertCircuitNotRan();
    expect(true)->toBeTrue();
});

it('assertCircuitNotRan fails when circuits were executed', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeCircuit($circuit);
    $fake->assertCircuitNotRan();
})->throws(ExpectationFailedException::class);

// -------------------------------------------------------------------------
// assertEntropyNotGenerated()
// -------------------------------------------------------------------------

it('assertEntropyNotGenerated passes when no entropy generated', function () {
    $fake = new QuantumFake;
    $fake->assertEntropyNotGenerated();
    expect(true)->toBeTrue();
});

it('assertEntropyNotGenerated fails when entropy was generated', function () {
    $fake = new QuantumFake;
    $fake->generateEntropy(128);
    $fake->assertEntropyNotGenerated();
})->throws(ExpectationFailedException::class);

// -------------------------------------------------------------------------
// assertCircuitRanTimes()
// -------------------------------------------------------------------------

it('assertCircuitRanTimes passes with correct count', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeCircuit($circuit);
    $fake->executeCircuit($circuit);
    $fake->assertCircuitRanTimes(2);
    expect(true)->toBeTrue();
});

it('assertCircuitRanTimes fails with wrong count', function () {
    $fake = new QuantumFake;
    $fake->assertCircuitRanTimes(1);
})->throws(ExpectationFailedException::class);

// -------------------------------------------------------------------------
// respondWithCounts() — stubs deterministic counts
// -------------------------------------------------------------------------

it('respondWithCounts overrides executeCircuit counts', function () {
    $fake = new QuantumFake;
    $fake->respondWithCounts(['00' => 7, '11' => 3]);
    $circuit = (new CircuitBuilder($fake))->qubits(2)->shots(10)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result->counts())->toBe(['00' => 7, '11' => 3]);
});

it('respondWithCounts returns self for chaining', function () {
    $fake = new QuantumFake;

    expect($fake->respondWithCounts(['0' => 1]))->toBe($fake);
});

// -------------------------------------------------------------------------
// implements AsynchronousDevice
// -------------------------------------------------------------------------

it('implements AsynchronousDevice', function () {
    $fake = new QuantumFake;

    expect($fake)->toBeInstanceOf(AsynchronousDevice::class);
});

// -------------------------------------------------------------------------
// submitCircuit() — dispatched circuits recorded separately from ran ones
// -------------------------------------------------------------------------

it('submitCircuit records the circuit as dispatched, not ran', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->submitCircuit($circuit);

    expect($fake->dispatchedCircuits())->toHaveCount(1)
        ->and($fake->dispatchedCircuits()[0])->toBe($circuit)
        ->and($fake->recordedCircuits())->toBeEmpty()
        ->and($fake->hasExecutedCircuits())->toBeFalse();
});

it('submitCircuit returns a fake task ARN', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $arn = $fake->submitCircuit($circuit);

    expect($arn)->toBeString()->toStartWith('arn:aws:braket:::fake-task/');
});

it('submitCircuit returns incrementing ARNs for successive dispatches', function () {
    $fake = new QuantumFake;
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(1)->measure();

    $arn1 = $fake->submitCircuit($circuit1);
    $arn2 = $fake->submitCircuit($circuit2);

    expect($arn1)->not->toBe($arn2);
});

// -------------------------------------------------------------------------
// dispatchedCircuits() accessor
// -------------------------------------------------------------------------

it('dispatchedCircuits returns empty array initially', function () {
    $fake = new QuantumFake;

    expect($fake->dispatchedCircuits())->toBeEmpty();
});

it('dispatchedCircuits count increments with each submission', function () {
    $fake = new QuantumFake;
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->submitCircuit($circuit1);
    $fake->submitCircuit($circuit2);

    expect($fake->dispatchedCircuits())->toHaveCount(2);
});

// -------------------------------------------------------------------------
// checkTask() — deterministic completion
// -------------------------------------------------------------------------

it('checkTask returns a Completed snapshot with executeCircuit-equivalent counts', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->shots(100)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['00' => 50, '11' => 50]);
});

it('checkTask honours respondWithCounts', function () {
    $fake = new QuantumFake;
    $fake->respondWithCounts(['01' => 9, '10' => 1]);
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['01' => 9, '10' => 1]);
});

// -------------------------------------------------------------------------
// respondWithTaskStatus() — polling / non-terminal / failed states
// -------------------------------------------------------------------------

it('respondWithTaskStatus overrides checkTask status', function () {
    $fake = new QuantumFake;
    $fake->respondWithTaskStatus(TaskStatus::Running);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->status)->toBe(TaskStatus::Running)
        ->and($snapshot->counts)->toBeNull();
});

it('respondWithTaskStatus can simulate a failed task', function () {
    $fake = new QuantumFake;
    $fake->respondWithTaskStatus(TaskStatus::Failed);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->status)->toBe(TaskStatus::Failed)
        ->and($snapshot->counts)->toBeNull();
});

it('respondWithTaskStatus returns self for chaining', function () {
    $fake = new QuantumFake;

    expect($fake->respondWithTaskStatus(TaskStatus::Failed))->toBe($fake);
});

// -------------------------------------------------------------------------
// assertCircuitDispatched()
// -------------------------------------------------------------------------

it('assertCircuitDispatched passes after a circuit is submitted', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $fake->submitCircuit($circuit);

    $fake->assertCircuitDispatched();
    expect(true)->toBeTrue();
});

it('assertCircuitDispatched fails when nothing was submitted', function () {
    $fake = new QuantumFake;

    expect(fn () => $fake->assertCircuitDispatched())
        ->toThrow(AssertionFailedError::class);
});

it('assertCircuitDispatched with callback passes when a submitted circuit matches', function () {
    $fake = new QuantumFake;
    $circuit1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(3)->measure();

    $fake->submitCircuit($circuit1);
    $fake->submitCircuit($circuit2);

    $fake->assertCircuitDispatched(fn (CircuitBuilder $c) => $c->qubitCount() === 3);
    expect(true)->toBeTrue();
});

it('assertCircuitDispatched with callback fails when no submitted circuit matches', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->submitCircuit($circuit);

    expect(fn () => $fake->assertCircuitDispatched(fn (CircuitBuilder $c) => $c->qubitCount() === 5))
        ->toThrow(AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// assertCircuitNotDispatched()
// -------------------------------------------------------------------------

it('assertCircuitNotDispatched passes when nothing was submitted', function () {
    $fake = new QuantumFake;
    $fake->assertCircuitNotDispatched();
    expect(true)->toBeTrue();
});

it('assertCircuitNotDispatched fails when a circuit was submitted', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->submitCircuit($circuit);
    $fake->assertCircuitNotDispatched();
})->throws(ExpectationFailedException::class);

// -------------------------------------------------------------------------
// assertCircuitDispatchedTimes()
// -------------------------------------------------------------------------

it('assertCircuitDispatchedTimes passes with the correct count', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->submitCircuit($circuit);
    $fake->submitCircuit($circuit);
    $fake->assertCircuitDispatchedTimes(2);
    expect(true)->toBeTrue();
});

it('assertCircuitDispatchedTimes fails with the wrong count', function () {
    $fake = new QuantumFake;
    $fake->assertCircuitDispatchedTimes(1);
})->throws(ExpectationFailedException::class);

// ---------------------------------------------------------------------------
// Entropy quality — the fake must be usable with rejection sampling
// ---------------------------------------------------------------------------

it('produces entropy that rejection sampling can actually consume', function () {
    $generator = new EntropyGenerator(new QuantumFake);

    expect($generator->integer(0, 8))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(8);
});

it('does not repeat the same byte forever', function () {
    $bytes = (new QuantumFake)->generateEntropy(256);

    expect(count(array_unique(str_split($bytes))))->toBeGreaterThan(1);
});

it('continues its entropy sequence across calls', function () {
    $fake = new QuantumFake;

    expect($fake->generateEntropy(64))->not->toBe($fake->generateEntropy(64));
});

it('asserts how many times entropy was generated', function () {
    $fake = new QuantumFake;
    $fake->generateEntropy(128);
    $fake->generateEntropy(64);

    $fake->assertEntropyGeneratedTimes(2);
});

it('fails when the entropy generation count does not match', function () {
    $fake = new QuantumFake;
    $fake->generateEntropy(128);

    expect(fn () => $fake->assertEntropyGeneratedTimes(3))
        ->toThrow(AssertionFailedError::class);
});

// -------------------------------------------------------------------------
// Batch execution
// -------------------------------------------------------------------------

it('assertBatchRan passes when batch was executed', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeBatch([$c]);

    $fake->assertBatchRan();
    $fake->assertBatchRan(fn ($b) => count($b) === 1);
});

it('assertBatchNotRan passes when no batches executed', function () {
    $fake = new QuantumFake;
    $fake->assertBatchNotRan();
});

it('assertBatchNotRan fails when batch was executed', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->measure();
    $fake->executeBatch([$c]);

    expect(fn () => $fake->assertBatchNotRan())->toThrow(AssertionFailedError::class);
});

it('assertBatchRanTimes verifies count', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeBatch([$c]);
    $fake->executeBatch([$c]);

    $fake->assertBatchRanTimes(2);
});

it('records individual circuits from batch', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeBatch([$c]);

    $fake->assertCircuitRan();
});

it('assertCircuitNotRan fails after batch', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeBatch([$c]);

    expect(fn () => $fake->assertCircuitNotRan())->toThrow(AssertionFailedError::class);
});

it('returns correct counts from executeBatch', function () {
    $fake = new QuantumFake;
    $c = (new CircuitBuilder($fake))->qubits(1)->shots(100)->measure();

    $result = $fake->executeBatch([$c]);

    expect($result)->toBeInstanceOf(BatchResult::class);
    expect($result->get(0)->counts())->toBe(['0' => 50, '1' => 50]);
});
