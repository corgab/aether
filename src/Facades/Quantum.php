<?php

declare(strict_types=1);

namespace Aether\Facades;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;
use Aether\Testing\QuantumFake;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the QuantumManager.
 *
 * @method static QuantumDevice driver(?string $name = null)
 * @method static CircuitBuilder circuit(?string $driver = null)
 * @method static EntropyGenerator entropy(?string $driver = null)
 * @method static void extend(string $name, Closure $callback)
 * @method static QuantumFake fake()
 *
 * @see \Aether\QuantumManager
 */
class Quantum extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Aether\QuantumManager::class;
    }
}
