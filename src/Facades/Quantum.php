<?php

declare(strict_types=1);

namespace Aether\Facades;

use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Entropy\EntropyGenerator;
use Aether\QuantumManager;
use Aether\Results\CircuitResult;
use Aether\Testing\QuantumFake;
use Aether\Testing\ResultSequence;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the QuantumManager.
 *
 * @method static QuantumDevice driver(?string $name = null)
 * @method static CircuitBuilder circuit(?string $driver = null)
 * @method static EntropyGenerator entropy(?string $driver = null)
 * @method static \Aether\Bridge\PythonBridge bridge()
 * @method static void extend(string $name, Closure $callback)
 * @method static QuantumFake fake(array<string, int>|CircuitResult|Closure(CircuitBuilder): (array<string, int>|CircuitResult|null)|ResultSequence|null $stub = null)
 * @method static \Aether\QuantumManager forgetDrivers()
 *
 * @see QuantumManager
 */
class Quantum extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return QuantumManager::class;
    }
}
