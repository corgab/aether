<?php

declare(strict_types=1);

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\AsynchronousDevice;
use Aether\Contracts\EstimatesCost;
use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;
use Aether\Results\BatchResult;
use Aether\Results\CircuitResult;
use Aether\Results\CostEstimate;
use Aether\Tasks\TaskStatus;
use Aether\Testing\QuantumFake;
use Aether\Testing\ResultSequence;
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

it('respondWithTaskStatus can simulate a backend failure reason', function () {
    $fake = new QuantumFake;
    $fake->respondWithTaskStatus(TaskStatus::Failed, 'boom');
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $snapshot = $fake->checkTask($fake->submitCircuit($circuit));

    expect($snapshot->status)->toBe(TaskStatus::Failed)
        ->and($snapshot->error)->toBe('boom');
});

it('respondWithTaskStatus clears a previously stubbed failure reason', function () {
    $fake = new QuantumFake;
    $fake->respondWithTaskStatus(TaskStatus::Failed, 'boom');
    $fake->respondWithTaskStatus(TaskStatus::Cancelled);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $snapshot = $fake->checkTask($fake->submitCircuit($circuit));

    expect($snapshot->status)->toBe(TaskStatus::Cancelled)
        ->and($snapshot->error)->toBeNull();
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

// -------------------------------------------------------------------------
// Quantum::fake($stub) — canned counts array
// -------------------------------------------------------------------------

it('constructor accepts a canned counts array and returns it from every circuit', function () {
    $fake = new QuantumFake(['00' => 700, '11' => 324]);
    $circuit1 = (new CircuitBuilder($fake))->qubits(2)->measure();
    $circuit2 = (new CircuitBuilder($fake))->qubits(2)->measure();

    expect($fake->executeCircuit($circuit1)->counts())->toBe(['00' => 700, '11' => 324])
        ->and($fake->executeCircuit($circuit2)->counts())->toBe(['00' => 700, '11' => 324]);
});

it('respondWith accepts a canned counts array', function () {
    $fake = new QuantumFake;
    $fake->respondWith(['0' => 3, '1' => 7]);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['0' => 3, '1' => 7]);
});

it('respondWith returns self for chaining', function () {
    $fake = new QuantumFake;

    expect($fake->respondWith(['0' => 1]))->toBe($fake);
});

it('accepts an empty counts array so the empty-result branch can be stubbed', function () {
    $fake = new QuantumFake([]);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $result = $fake->executeCircuit($circuit);

    expect($result->counts())->toBe([])
        ->and($result->shots())->toBe(0);
});

it('rejects a counts array with a non-bitstring key', function () {
    expect(fn () => new QuantumFake(['not-a-bitstring' => 1]))
        ->toThrow(InvalidArgumentException::class, 'is not a valid bitstring');
});

it('rejects a counts array with a negative count', function () {
    expect(fn () => new QuantumFake(['0' => -1]))
        ->toThrow(InvalidArgumentException::class, 'non-negative integer');
});

it('rejects a counts array with a non-integer count', function () {
    expect(fn () => new QuantumFake(['0' => 'many']))
        ->toThrow(InvalidArgumentException::class, 'non-negative integer');
});

// -------------------------------------------------------------------------
// Quantum::fake($stub) — canned CircuitResult via QuantumFake::result()
// -------------------------------------------------------------------------

it('QuantumFake::result builds a CircuitResult from counts', function () {
    $result = QuantumFake::result(['00' => 700, '11' => 324]);

    expect($result)->toBeInstanceOf(CircuitResult::class)
        ->and($result->counts())->toBe(['00' => 700, '11' => 324]);
});

it('QuantumFake::result validates its counts eagerly', function () {
    expect(fn () => QuantumFake::result(['bad key' => 1]))
        ->toThrow(InvalidArgumentException::class, 'is not a valid bitstring');
});

it('constructor accepts a canned CircuitResult and returns the same instance', function () {
    $canned = QuantumFake::result(['01' => 1000]);
    $fake = new QuantumFake($canned);
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    expect($fake->executeCircuit($circuit))->toBe($canned);
});

it('respondWith accepts a CircuitResult directly', function () {
    $fake = new QuantumFake;
    $fake->respondWith(QuantumFake::result(['10' => 42]));
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['10' => 42]);
});

// -------------------------------------------------------------------------
// Quantum::fake($stub) — closure stub, per-circuit branching, null fallthrough
// -------------------------------------------------------------------------

it('constructor accepts a closure evaluated per circuit', function () {
    $fake = new QuantumFake(
        fn (CircuitBuilder $c): array => $c->qubitCount() === 2 ? ['00' => 1000] : ['0' => 1000]
    );

    $twoQubit = (new CircuitBuilder($fake))->qubits(2)->measure();
    $oneQubit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($twoQubit)->counts())->toBe(['00' => 1000])
        ->and($fake->executeCircuit($oneQubit)->counts())->toBe(['0' => 1000]);
});

it('closure stub receives the CircuitBuilder that is about to execute', function () {
    $seen = null;
    $fake = new QuantumFake(function (CircuitBuilder $c) use (&$seen): array {
        $seen = $c;

        return ['0' => 1];
    });
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect($seen)->toBe($circuit);
});

it('closure stub may return a CircuitResult', function () {
    $fake = new QuantumFake(fn (CircuitBuilder $c): CircuitResult => QuantumFake::result(['1' => 5]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['1' => 5]);
});

it('closure stub returning null falls through to the deterministic default', function () {
    $fake = new QuantumFake(fn (CircuitBuilder $c): ?array => null);
    $circuit = (new CircuitBuilder($fake))->qubits(2)->shots(10)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['00' => 5, '11' => 5]);
});

it('closure stub result is validated when it returns an array', function () {
    $fake = new QuantumFake(fn (CircuitBuilder $c): array => ['nope' => 1]);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect(fn () => $fake->executeCircuit($circuit))
        ->toThrow(InvalidArgumentException::class, 'is not a valid bitstring');
});

// -------------------------------------------------------------------------
// Quantum::fake($stub) — sequences via QuantumFake::sequence()
// -------------------------------------------------------------------------

it('QuantumFake::sequence returns results in order', function () {
    $fake = new QuantumFake(QuantumFake::sequence([
        ['0' => 10],
        ['1' => 10],
    ]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['0' => 10])
        ->and($fake->executeCircuit($circuit)->counts())->toBe(['1' => 10]);
});

it('sequence accepts a mix of counts arrays and CircuitResult instances', function () {
    $fake = new QuantumFake(QuantumFake::sequence([
        ['0' => 1],
        QuantumFake::result(['1' => 2]),
    ]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['0' => 1])
        ->and($fake->executeCircuit($circuit)->counts())->toBe(['1' => 2]);
});

it('sequence built via push() advances in push order', function () {
    $sequence = QuantumFake::sequence()->push(['0' => 1])->push(['1' => 1]);
    $fake = new QuantumFake($sequence);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['0' => 1])
        ->and($fake->executeCircuit($circuit)->counts())->toBe(['1' => 1]);
});

it('sequence throws once exhausted', function () {
    $fake = new QuantumFake(QuantumFake::sequence([['0' => 1]]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    expect(fn () => $fake->executeCircuit($circuit))
        ->toThrow(OutOfBoundsException::class, 'The stubbed result sequence is empty');
});

it('sequence falls back to whenEmpty() instead of throwing once exhausted', function () {
    $sequence = QuantumFake::sequence([['0' => 1]])->whenEmpty(['1' => 1]);
    $fake = new QuantumFake($sequence);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['0' => 1])
        ->and($fake->executeCircuit($circuit)->counts())->toBe(['1' => 1])
        ->and($fake->executeCircuit($circuit)->counts())->toBe(['1' => 1]);
});

it('ResultSequence::isEmpty reflects the queue, not the whenEmpty fallback', function () {
    $sequence = new ResultSequence([['0' => 1]]);

    expect($sequence->isEmpty())->toBeFalse();
    $sequence->next();
    expect($sequence->isEmpty())->toBeTrue();
});

it('sequence validates each pushed counts array eagerly', function () {
    expect(fn () => QuantumFake::sequence([['bad key' => 1]]))
        ->toThrow(InvalidArgumentException::class, 'is not a valid bitstring');
});

it('sequence advances once per circuit in a batch', function () {
    $fake = new QuantumFake(QuantumFake::sequence([['0' => 1], ['1' => 1]]));
    $c1 = (new CircuitBuilder($fake))->qubits(1)->measure();
    $c2 = (new CircuitBuilder($fake))->qubits(1)->measure();

    $result = $fake->executeBatch([$c1, $c2]);

    expect($result->get(0)->counts())->toBe(['0' => 1])
        ->and($result->get(1)->counts())->toBe(['1' => 1]);
});

// -------------------------------------------------------------------------
// Stubs applied to checkTask()
// -------------------------------------------------------------------------

it('checkTask honours a canned CircuitResult stub', function () {
    $fake = new QuantumFake(QuantumFake::result(['01' => 9, '10' => 1]));
    $circuit = (new CircuitBuilder($fake))->qubits(2)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->status)->toBe(TaskStatus::Completed)
        ->and($snapshot->counts)->toBe(['01' => 9, '10' => 1]);
});

it('checkTask honours a closure stub for the submitted circuit', function () {
    $fake = new QuantumFake(fn (CircuitBuilder $c): array => ['0' => $c->shotCount()]);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->shots(250)->measure();

    $arn = $fake->submitCircuit($circuit);
    $snapshot = $fake->checkTask($arn);

    expect($snapshot->counts)->toBe(['0' => 250]);
});

it('checkTask resolves a sequence entry once per task and keeps it across polls', function () {
    $fake = new QuantumFake(QuantumFake::sequence([['0' => 1], ['1' => 1]]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $first = $fake->submitCircuit($circuit);
    $second = $fake->submitCircuit($circuit);

    expect($fake->checkTask($first)->counts)->toBe(['0' => 1])
        ->and($fake->checkTask($first)->counts)->toBe(['0' => 1])
        ->and($fake->checkTask($second)->counts)->toBe(['1' => 1]);
});

it('checkTask does not consume a sequence entry for an unknown task', function () {
    $fake = new QuantumFake(QuantumFake::sequence([['0' => 1]]));
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();
    $arn = $fake->submitCircuit($circuit);

    expect($fake->checkTask('arn:aws:braket:::fake-task/unknown')->counts)->toBeNull()
        ->and($fake->checkTask($arn)->counts)->toBe(['0' => 1]);
});

// -------------------------------------------------------------------------
// respondEntropyWith() — fixed bytes, hex, closure
// -------------------------------------------------------------------------

it('respondEntropyWith a fixed byte tiles it to the requested length', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith("\xFF");

    expect($fake->generateEntropy(24))->toBe("\xFF\xFF\xFF");
});

it('respondEntropyWith tiles a multi-byte fixed stub', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith("\xAA\xBB");

    expect($fake->generateEntropy(24))->toBe("\xAA\xBB\xAA");
});

it('respondEntropyWith returns self for chaining', function () {
    $fake = new QuantumFake;

    expect($fake->respondEntropyWith("\x00"))->toBe($fake);
});

it('rejects an empty fixed entropy stub', function () {
    $fake = new QuantumFake;

    expect(fn () => $fake->respondEntropyWith(''))
        ->toThrow(InvalidArgumentException::class, 'cannot be an empty string');
});

it('QuantumFake::hex decodes a hex string into raw bytes for respondEntropyWith', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith(QuantumFake::hex('ff00'));

    expect($fake->generateEntropy(16))->toBe("\xFF\x00");
});

it('QuantumFake::hex rejects an odd-length or non-hex string', function () {
    expect(fn () => QuantumFake::hex('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => QuantumFake::hex('zz'))->toThrow(InvalidArgumentException::class);
});

it('respondEntropyWith a closure receives the requested bit count', function () {
    $seenBits = null;
    $fake = new QuantumFake;
    $fake->respondEntropyWith(function (int $bits) use (&$seenBits): string {
        $seenBits = $bits;

        return str_repeat("\x01", (int) ceil($bits / 8));
    });

    $fake->generateEntropy(24);

    expect($seenBits)->toBe(24);
});

it('respondEntropyWith closure returning null falls through to the deterministic counter', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith(fn (int $bits): ?string => null);

    expect($fake->generateEntropy(16))->toBe("\x00\x01");
});

it('respondEntropyWith closure must return exactly the expected byte length', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith(fn (int $bits): string => "\x00"); // always 1 byte

    expect(fn () => $fake->generateEntropy(24))
        ->toThrow(InvalidArgumentException::class, 'expected 3');
});

it('generateEntropy still records bit-lengths and dispatches when stubbed', function () {
    $fake = new QuantumFake;
    $fake->respondEntropyWith("\x00");

    $fake->generateEntropy(128);

    $fake->assertEntropyGenerated(128);
});

// -------------------------------------------------------------------------
// BC — Quantum::fake() with no arguments keeps the original deterministic behaviour
// -------------------------------------------------------------------------

it('constructing QuantumFake with no stub keeps the deterministic default', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake))->qubits(2)->shots(1000)->measure();

    expect($fake->executeCircuit($circuit)->counts())->toBe(['00' => 500, '11' => 500])
        ->and($fake->generateEntropy(16))->toBe("\x00\x01");
});

// -------------------------------------------------------------------------
// Assertions still work when a stub is in play
// -------------------------------------------------------------------------

it('assertCircuitRan still passes when a canned stub is configured', function () {
    $fake = new QuantumFake(['0' => 1]);
    $circuit = (new CircuitBuilder($fake))->qubits(1)->measure();

    $fake->executeCircuit($circuit);

    $fake->assertCircuitRan(fn (CircuitBuilder $c) => $c->qubitCount() === 1);
});

// -------------------------------------------------------------------------
// estimateCost() — EstimatesCost parity with the aws driver
// -------------------------------------------------------------------------

it('implements EstimatesCost', function () {
    expect(new QuantumFake)->toBeInstanceOf(EstimatesCost::class);
});

it('estimateCost defaults to a free estimate covering the requested shots', function () {
    $estimate = (new QuantumFake)->estimateCost(1000);

    expect($estimate)->toBeInstanceOf(CostEstimate::class)
        ->and($estimate->amount)->toBe(0.0)
        ->and($estimate->currency)->toBe('USD')
        ->and($estimate->shots)->toBe(1000)
        ->and($estimate->breakdown)->toBe(['per_task' => 0.0, 'per_shot' => 0.0]);
});

it('respondCostWith stubs a fixed CostEstimate', function () {
    $stub = new CostEstimate(amount: 0.65, currency: 'USD', shots: 1000, breakdown: ['per_task' => 0.30, 'per_shot' => 0.35]);
    $fake = (new QuantumFake)->respondCostWith($stub);

    expect($fake->estimateCost(1))->toBe($stub);
});

it('respondCostWith accepts a closure receiving shots and tasks', function () {
    $fake = (new QuantumFake)->respondCostWith(
        fn (int $shots, int $tasks): CostEstimate => new CostEstimate(
            amount: $tasks * 0.30 + $shots * 0.001,
            currency: 'EUR',
            shots: $shots,
            breakdown: ['per_task' => $tasks * 0.30, 'per_shot' => $shots * 0.001],
        )
    );

    $estimate = $fake->estimateCost(100, 2);

    expect($estimate->amount)->toBe(0.7)
        ->and($estimate->currency)->toBe('EUR')
        ->and($estimate->shots)->toBe(100);
});

it('lets CircuitBuilder::estimateCost run against the fake', function () {
    $fake = new QuantumFake;
    $circuit = (new CircuitBuilder($fake, 'aws'))->qubits(2)->h(0)->measure()->shots(500);

    expect($circuit->estimateCost()->shots)->toBe(500);
});
