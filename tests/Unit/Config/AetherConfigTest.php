<?php

declare(strict_types=1);

use Aether\Config\AetherConfig;
use Illuminate\Config\Repository;

/**
 * Build a reader over the given `aether.*` values, without a Laravel app.
 *
 * @param  array<string, mixed>  $aether
 */
function aetherConfig(array $aether): AetherConfig
{
    return new AetherConfig(new Repository(['aether' => $aether]));
}

// -------------------------------------------------------------------------
// Defaults
// -------------------------------------------------------------------------

it('returns the documented defaults when nothing is configured', function () {
    $config = aetherConfig([]);

    expect($config->defaultDriver())->toBe('local')
        ->and($config->pythonPath())->toBe('python3')
        ->and($config->processTimeout())->toBe(300)
        ->and($config->queue())->toBeNull()
        ->and($config->pollInterval())->toBe(5)
        ->and($config->maxPollAttempts())->toBe(720)
        ->and($config->persistTasks())->toBeFalse()
        ->and($config->driver('local'))->toBe([]);
});

it('exposes each default as a constant so the literal lives in one place', function () {
    expect(AetherConfig::DEFAULT_DRIVER)->toBe('local')
        ->and(AetherConfig::DEFAULT_PYTHON_PATH)->toBe('python3')
        ->and(AetherConfig::DEFAULT_PROCESS_TIMEOUT)->toBe(300)
        ->and(AetherConfig::DEFAULT_POLL_INTERVAL)->toBe(5)
        ->and(AetherConfig::DEFAULT_MAX_POLL_ATTEMPTS)->toBe(720);
});

// -------------------------------------------------------------------------
// Configured values
// -------------------------------------------------------------------------

it('returns the configured values with their documented types', function () {
    $config = aetherConfig([
        'default' => 'aws',
        'python_path' => '/opt/venv/bin/python',
        'process_timeout' => 45,
        'queue' => 'quantum',
        'poll_interval' => 3,
        'max_poll_attempts' => 12,
        'persist_tasks' => true,
        'drivers' => ['aws' => ['region' => 'eu-west-1']],
    ]);

    expect($config->defaultDriver())->toBe('aws')
        ->and($config->pythonPath())->toBe('/opt/venv/bin/python')
        ->and($config->processTimeout())->toBe(45)
        ->and($config->queue())->toBe('quantum')
        ->and($config->pollInterval())->toBe(3)
        ->and($config->maxPollAttempts())->toBe(12)
        ->and($config->persistTasks())->toBeTrue()
        ->and($config->driver('aws'))->toBe(['region' => 'eu-west-1']);
});

it('casts the numeric strings env() hands over', function () {
    $config = aetherConfig(['process_timeout' => '45', 'poll_interval' => '3', 'max_poll_attempts' => '12']);

    expect($config->processTimeout())->toBe(45)
        ->and($config->pollInterval())->toBe(3)
        ->and($config->maxPollAttempts())->toBe(12);
});

it('trims the driver name, python path and queue it returns', function () {
    $config = aetherConfig(['default' => ' aws ', 'python_path' => ' /usr/bin/python3', 'queue' => 'quantum ']);

    expect($config->defaultDriver())->toBe('aws')
        ->and($config->pythonPath())->toBe('/usr/bin/python3')
        ->and($config->queue())->toBe('quantum');
});

it('falls back to the default for a blank or non-numeric integer option', function (mixed $raw) {
    $config = aetherConfig(['poll_interval' => $raw, 'max_poll_attempts' => $raw, 'process_timeout' => $raw]);

    expect($config->pollInterval())->toBe(5)
        ->and($config->maxPollAttempts())->toBe(720)
        ->and($config->processTimeout())->toBe(300);
})->with(['null' => [null], 'empty string' => [''], 'word' => ['soon'], 'boolean' => [true], 'array' => [[5]]]);

it('treats a blank default driver, python path or queue as unset', function (mixed $raw) {
    $config = aetherConfig(['default' => $raw, 'python_path' => $raw, 'queue' => $raw]);

    expect($config->defaultDriver())->toBe('local')
        ->and($config->pythonPath())->toBe('python3')
        ->and($config->queue())->toBeNull();
})->with(['null' => [null], 'empty string' => [''], 'whitespace' => ['  '], 'array' => [['aws']]]);

it('reads persist_tasks from booleans and their env() spellings', function (mixed $raw, bool $expected) {
    expect(aetherConfig(['persist_tasks' => $raw])->persistTasks())->toBe($expected);
})->with([
    'true' => [true, true],
    '"true"' => ['true', true],
    '"1"' => ['1', true],
    'false' => [false, false],
    '"false"' => ['false', false],
    '"0"' => ['0', false],
    'null' => [null, false],
    'garbage' => ['maybe', false],
]);

it('returns an empty array for a driver entry that is missing or not an array', function () {
    $config = aetherConfig(['drivers' => ['aws' => 'oops']]);

    expect($config->driver('aws'))->toBe([])
        ->and($config->driver('ionq'))->toBe([]);
});

// -------------------------------------------------------------------------
// Live reads
// -------------------------------------------------------------------------

it('reads the repository on every call instead of snapshotting', function () {
    $repository = new Repository(['aether' => ['poll_interval' => 5]]);
    $config = new AetherConfig($repository);

    expect($config->pollInterval())->toBe(5);

    $repository->set('aether.poll_interval', 9);

    expect($config->pollInterval())->toBe(9);
});
