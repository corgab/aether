<?php

declare(strict_types=1);

use Aether\Events\CircuitCompleted;
use Aether\Results\CircuitResult;

it('exposes the driver, circuit definition, result and task arn', function () {
    $result = new CircuitResult(['00' => 500, '11' => 500]);
    $circuit = ['qubits' => 2, 'gates' => [], 'shots' => 1000];

    $event = new CircuitCompleted(
        driver: 'aws',
        circuit: $circuit,
        result: $result,
        taskArn: 'arn:aws:braket:us-east-1:123456789012:quantum-task/abc',
    );

    expect($event->driver)->toBe('aws')
        ->and($event->circuit)->toBe($circuit)
        ->and($event->result)->toBe($result)
        ->and($event->taskArn)->toBe('arn:aws:braket:us-east-1:123456789012:quantum-task/abc');
});

it('defaults the task arn to null for synchronous executions', function () {
    $event = new CircuitCompleted(
        driver: 'local',
        circuit: ['qubits' => 1, 'gates' => [], 'shots' => 100],
        result: new CircuitResult(['0' => 100]),
    );

    expect($event->taskArn)->toBeNull();
});
