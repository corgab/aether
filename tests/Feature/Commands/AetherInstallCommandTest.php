<?php

declare(strict_types=1);

it('publishes the config file and returns SUCCESS', function () {
    $this->artisan('aether:install')
        ->expectsOutputToContain('Installing Aether...')
        ->expectsOutputToContain('Aether installation complete.')
        ->assertSuccessful();
});
