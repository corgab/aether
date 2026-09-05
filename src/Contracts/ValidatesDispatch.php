<?php

declare(strict_types=1);

namespace Aether\Contracts;

use Aether\Exceptions\InvalidDriverConfigException;

/**
 * Optional contract for asynchronous devices that can reject a dispatch
 * before the submission job is queued.
 *
 * CircuitBuilder::dispatch() calls validateDispatch() on devices implementing
 * this contract, so a misconfiguration that would only surface inside a
 * queue worker is reported at the call site instead.
 */
interface ValidatesDispatch
{
    /**
     * @throws InvalidDriverConfigException
     */
    public function validateDispatch(): void;
}
