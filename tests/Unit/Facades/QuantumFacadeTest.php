<?php

declare(strict_types=1);

use Aether\Facades\Quantum;
use Aether\QuantumManager;

/**
 * The facade forwards every call to QuantumManager at runtime, but IDEs and
 * static analysis only know the methods listed as @method annotations, so the
 * docblock must name each public method the manager declares.
 */
function facadeMethodAnnotations(): array
{
    $docblock = (string) (new ReflectionClass(Quantum::class))->getDocComment();

    preg_match_all('/@method\s+static\s+\S+\s+([A-Za-z_]\w*)\(/', $docblock, $matches);

    return $matches[1];
}

function managerPublicMethods(): array
{
    $methods = [];

    foreach ((new ReflectionClass(QuantumManager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === QuantumManager::class && ! $method->isStatic() && ! $method->isConstructor()) {
            $methods[] = $method->getName();
        }
    }

    return $methods;
}

it('annotates every public QuantumManager method on the facade', function () {
    $missing = array_diff(managerPublicMethods(), facadeMethodAnnotations());

    expect(array_values($missing))->toBe([]);
});

it('annotates batch() with the BatchBuilder it returns', function () {
    $docblock = (string) (new ReflectionClass(Quantum::class))->getDocComment();

    expect($docblock)->toContain('@method static BatchBuilder batch(');
});
