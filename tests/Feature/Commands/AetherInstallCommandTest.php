<?php

declare(strict_types=1);

use Aether\Facades\Quantum;
use Aether\QuantumManager;

/**
 * Bind a QuantumManager double whose circuit() call always throws, simulating
 * a failed test circuit without depending on a real Python/braket install.
 */
function bindFailingQuantumManager(): void
{
    $manager = Mockery::mock(QuantumManager::class);
    $manager->shouldReceive('circuit')->andThrow(new RuntimeException('boom'));

    app()->instance(QuantumManager::class, $manager);
}

// The test circuit is faked by default so these tests don't depend on a real
// Python/braket environment being available on the machine running them.
beforeEach(function () {
    Quantum::fake();
});

it('returns FAILURE when the test circuit fails to run', function () {
    bindFailingQuantumManager();

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertFailed()
        ->expectsOutputToContain('test circuit failed');
});

it('does not display the installation complete message when the test circuit fails', function () {
    bindFailingQuantumManager();

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertFailed()
        ->doesntExpectOutputToContain('Aether installation complete');
});

it('runs the install command successfully', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful();
});

it('outputs installing message', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Installing Aether');
});

it('publishes the config file', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('PUBLISHED');
});

it('displays installation complete message', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Aether installation complete');
});
