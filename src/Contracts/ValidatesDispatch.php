<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Exceptions\InvalidDriverConfigException;

/**
 * Optional contract for asynchronous devices that can reject a dispatch
 * before running anything.
 *
 * CircuitBuilder::dispatch() calls validateDispatch() without a connection,
 * since the queue connection is only fixed once the pending dispatch is
 * sent; SubmitQuantumCircuit calls it again from the worker with the
 * connection the job actually runs on, so checks that depend on it (such as
 * whether two jobs share a process) are made against the real value.
 */
interface ValidatesDispatch
{
    /**
     * @param  string|null  $queueConnection  The queue connection the submission job runs on, or null when unknown.
     *
     * @throws InvalidDriverConfigException
     */
    public function validateDispatch(?string $queueConnection = null): void;
}
