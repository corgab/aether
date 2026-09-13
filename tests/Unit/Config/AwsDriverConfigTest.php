<?php

declare(strict_types=1);

use Aether\Config\AwsDriverConfig;
use Aether\Config\DriverConfig;
use Aether\Exceptions\InvalidDriverConfigException;

it('is a DriverConfig with the shared options typed too', function () {
    $config = new AwsDriverConfig('aws', ['max_qubits' => '12', 'synchronous_safe' => 'false']);

    expect($config)->toBeInstanceOf(DriverConfig::class)
        ->and($config->maxQubits)->toBe(12)
        ->and($config->synchronousSafe)->toBeFalse();
});

it('applies the documented defaults when pricing and the ceiling are absent', function () {
    $config = new AwsDriverConfig('aws', []);

    expect($config->maxCostPerRun)->toBeNull()
        ->and($config->perTaskRate)->toBeNull()
        ->and($config->perShotRate)->toBeNull()
        ->and($config->currency)->toBe(AwsDriverConfig::DEFAULT_CURRENCY)
        ->and($config->missingRates())->toBe(['pricing.per_task', 'pricing.per_shot']);
});

it('casts the pricing rates and currency', function () {
    $config = new AwsDriverConfig('aws', [
        'pricing' => ['per_task' => '0.30', 'per_shot' => 0.00035, 'currency' => 'EUR'],
    ]);

    expect($config->perTaskRate)->toBe(0.30)
        ->and($config->perShotRate)->toBe(0.00035)
        ->and($config->currency)->toBe('EUR')
        ->and($config->missingRates())->toBe([]);
});

it('accepts a zero rate as configured, not missing', function () {
    $config = new AwsDriverConfig('aws', ['pricing' => ['per_task' => 0, 'per_shot' => '0']]);

    expect($config->perTaskRate)->toBe(0.0)
        ->and($config->perShotRate)->toBe(0.0)
        ->and($config->missingRates())->toBe([]);
});

it('reports only the blank rates as missing', function () {
    $config = new AwsDriverConfig('aws', ['pricing' => ['per_task' => 0.30, 'per_shot' => '']]);

    expect($config->missingRates())->toBe(['pricing.per_shot']);
});

it('rejects a pricing entry that is not an array', function () {
    expect(fn () => new AwsDriverConfig('aws', ['pricing' => 'cheap']))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [pricing]: expected an array of rates or null');
});

it('rejects a rate that is not a non-negative number', function (string $rate, mixed $raw) {
    expect(fn () => new AwsDriverConfig('aws', ['pricing' => [$rate => $raw]]))
        ->toThrow(InvalidDriverConfigException::class, "invalid value for [pricing.{$rate}]: expected a non-negative number or null");
})->with([
    'negative per_task' => ['per_task', -0.1],
    'word per_shot' => ['per_shot', 'free'],
    'boolean per_task' => ['per_task', true],
]);

it('rejects a currency that is not a string', function () {
    expect(fn () => new AwsDriverConfig('aws', ['pricing' => ['currency' => ['USD']]]))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [pricing.currency]: expected a string or null');
});

it('casts max_cost_per_run from the number or numeric string env() yields', function (mixed $raw, ?float $expected) {
    expect((new AwsDriverConfig('aws', ['max_cost_per_run' => $raw]))->maxCostPerRun)->toBe($expected);
})->with([
    'float' => [1.5, 1.5],
    'int' => [2, 2.0],
    'numeric string' => ['0.65', 0.65],
    'zero' => [0, 0.0],
    'blank string' => ['', null],
    'null' => [null, null],
]);

it('rejects a max_cost_per_run that is not a non-negative number', function (mixed $raw) {
    expect(fn () => new AwsDriverConfig('aws', ['max_cost_per_run' => $raw]))
        ->toThrow(InvalidDriverConfigException::class, 'invalid value for [max_cost_per_run]: expected a non-negative number or null');
})->with(['negative' => [-1], 'word' => ['unlimited'], 'boolean' => [true]]);
