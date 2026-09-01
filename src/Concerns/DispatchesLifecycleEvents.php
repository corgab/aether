<?php

declare(strict_types=1);

namespace Aether\Concerns;

/**
 * Fire lifecycle events through Laravel's event dispatcher, when one is bound.
 *
 * Used by both AbstractQuantumDriver and QuantumFake, which are exercised
 * directly via `new` from unit tests that never boot a Laravel application
 * (see tests/Unit/Drivers and tests/Unit/Testing). Calling the event()
 * helper unconditionally there would fail to resolve the 'events' binding,
 * so dispatch is skipped instead of erroring when no dispatcher is bound —
 * real usage of the package always runs inside a booted application, where
 * the guard is a no-op and events fire normally.
 */
trait DispatchesLifecycleEvents
{
    private function dispatchEvent(object $event): void
    {
        if (app()->bound('events')) {
            event($event);
        }
    }
}
