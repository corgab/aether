<?php

declare(strict_types=1);

use Aether\Events\CircuitExecuted;
use Aether\Results\CircuitResult;

it('exposes the driver, circuit definition and result', function () {
    $result = new CircuitResult(['00' => 500, '11' => 500]);
    $circuit = ['qubits' => 2, 'gates' => [], 'shots' => 1000];

    $event = new CircuitExecuted(
        driver: 'local',
        circuit: $circuit,
        result: $result,
    );

    expect($event->driver)->toBe('local')
        ->and($event->circuit)->toBe($circuit)
        ->and($event->result)->toBe($result);
});
