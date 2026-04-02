<?php

declare(strict_types=1);

it('runs the install command successfully', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful();
});

it('outputs installing message', function () {
    $this->artisan('aether:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Installing Aether');
});
