<?php

declare(strict_types=1);

use Aether\Config\DriverConfig;
use Aether\Exceptions\InvalidDriverConfigException;

// -------------------------------------------------------------------------
// Defaults
// -------------------------------------------------------------------------

it('applies the documented defaults to an empty array', function () {
    $config = new DriverConfig('local', []);

    expect($config->driver)->toBe('local')
        ->and($config->maxQubits)->toBeNull()
        ->and($config->entropyQubits)->toBe(DriverConfig::DEFAULT_ENTROPY_QUBITS)
        ->and($config->synchronousSafe)->toBeNull()
        ->and($config->toArray())->toBe([]);
});

it('treats null and blank strings as unset', function (mixed $blank) {
    $config = new DriverConfig('local', [
        'max_qubits' => $blank,
        'entropy_qubits' => $blank,
        'synchronous_safe' => $blank,
    ]);

    expect($config->maxQubits)->toBeNull()
        ->and($config->entropyQubits)->toBe(16)
        ->and($config->synchronousSafe)->toBeNull();
})->with(['null' => [null], 'empty string' => [''], 'whitespace' => ['   ']]);

// -------------------------------------------------------------------------
// Typed options
// -------------------------------------------------------------------------

it('casts max_qubits from the int or numeric string env() yields', function (mixed $raw, int $expected) {
    expect((new DriverConfig('local', ['max_qubits' => $raw]))->maxQubits)->toBe($expected);
})->with(['int' => [25, 25], 'numeric string' => ['25', 25], 'padded string' => [' 8 ', 8]]);

it('rejects a max_qubits that is not a positive integer', function (mixed $raw) {
    expect(fn () => new DriverConfig('local', ['max_qubits' => $raw]))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [max_qubits]: expected a positive integer or null');
})->with([
    'zero' => [0],
    'negative' => [-1],
    'float' => [2.5],
    'word' => ['abc'],
    'boolean true' => [true],
    'array' => [[25]],
]);

it('names the driver, the key and the offending value in the message', function () {
    expect(fn () => new DriverConfig('local', ['max_qubits' => 'abc']))
        ->toThrow(InvalidDriverConfigException::class, "Driver [local] has an invalid value for [max_qubits]: expected a positive integer or null, got 'abc'. Set it in config/aether.php under drivers.local.");
});

it('casts entropy_qubits and falls back to the default for a non-positive count', function (mixed $raw, int $expected) {
    expect((new DriverConfig('local', ['entropy_qubits' => $raw]))->entropyQubits)->toBe($expected);
})->with([
    'positive int' => [8, 8],
    'numeric string' => ['12', 12],
    'zero' => [0, 16],
    'negative' => [-4, 16],
    'negative string' => ['-4', 16],
]);

it('rejects a non-numeric entropy_qubits', function () {
    expect(fn () => new DriverConfig('local', ['entropy_qubits' => 'many']))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [entropy_qubits]: expected an integer or null');
});

it('casts synchronous_safe from booleans and their env() spellings', function (mixed $raw, bool $expected) {
    expect((new DriverConfig('aws', ['synchronous_safe' => $raw]))->synchronousSafe)->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    '"false"' => ['false', false],
    '"0"' => ['0', false],
    '"off"' => ['off', false],
    '"true"' => ['true', true],
    '"1"' => ['1', true],
    'int 0' => [0, false],
]);

it('rejects a synchronous_safe that is not boolean-like', function (mixed $raw) {
    expect(fn () => new DriverConfig('aws', ['synchronous_safe' => $raw]))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [synchronous_safe]: expected a boolean or null');
})->with(['word' => ['maybe'], 'array' => [[true]]]);

// -------------------------------------------------------------------------
// Raw access
// -------------------------------------------------------------------------

it('keeps untyped keys reachable through get() and toArray()', function () {
    $raw = ['python_provider' => 'providers.custom', 'max_qubits' => '10', 'nested' => ['a' => 1]];
    $config = new DriverConfig('custom', $raw);

    expect($config->get('python_provider'))->toBe('providers.custom')
        ->and($config->get('missing'))->toBeNull()
        ->and($config->get('missing', 'fallback'))->toBe('fallback')
        ->and($config->toArray())->toBe($raw);
});

it('reports blank keys in the order asked', function () {
    $config = new DriverConfig('aws', ['region' => 'us-east-1', 'bucket' => '', 'device_arn' => null]);

    expect($config->isBlank('region'))->toBeFalse()
        ->and($config->isBlank('bucket'))->toBeTrue()
        ->and($config->isBlank('device_arn'))->toBeTrue()
        ->and($config->isBlank('absent'))->toBeTrue()
        ->and($config->blankKeys(['device_arn', 'region', 'bucket']))->toBe(['device_arn', 'bucket']);
});
