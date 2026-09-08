<?php

declare(strict_types=1);

use Aether\Facades\Quantum;
use Aether\QuantumManager;

/**
 * The facade forwards every call to QuantumManager at runtime, but IDEs and
 * static analysis only know the methods listed as @method annotations, so the
 * docblock must mirror the manager: every public method, with its return type.
 */

/** @return array<string, string|null> method name => annotated return type */
function facadeMethodAnnotations(): array
{
    $docblock = (string) (new ReflectionClass(Quantum::class))->getDocComment();

    preg_match_all('/@method\s+static\s+(?:(.+?)\s+)?([A-Za-z_]\w*)\(/', $docblock, $matches, PREG_SET_ORDER);

    $annotations = [];

    foreach ($matches as $match) {
        $annotations[$match[2]] = $match[1] !== '' ? $match[1] : null;
    }

    return $annotations;
}

/** @return array<string, ReflectionMethod> method name => reflection, every public method callable through the facade */
function managerPublicMethods(): array
{
    $methods = [];

    foreach ((new ReflectionClass(QuantumManager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isStatic() && ! $method->isConstructor() && ! str_starts_with($method->getName(), '__')) {
            $methods[$method->getName()] = $method;
        }
    }

    return $methods;
}

it('annotates every public method QuantumManager declares itself', function () {
    $declared = array_filter(
        managerPublicMethods(),
        fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === QuantumManager::class,
    );

    expect(array_values(array_diff(array_keys($declared), array_keys(facadeMethodAnnotations()))))->toBe([]);
});

it('does not annotate methods the manager no longer has', function () {
    $stale = array_diff(array_keys(facadeMethodAnnotations()), array_keys(managerPublicMethods()));

    expect(array_values($stale))->toBe([]);
});

it('annotates each method with the return type the manager declares', function () {
    $methods = managerPublicMethods();

    foreach (facadeMethodAnnotations() as $name => $annotated) {
        $declared = $methods[$name]->getReturnType();

        if (! $declared instanceof ReflectionNamedType || $annotated === null) {
            continue;
        }

        $expected = $declared->isBuiltin() ? $declared->getName() : basename(str_replace('\\', '/', $declared->getName()));
        $actual = basename(str_replace('\\', '/', $annotated));

        expect($actual)->toBe($expected, "@method {$name}() is annotated as returning {$annotated}, the manager returns {$declared->getName()}");
    }
});
