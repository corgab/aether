<?php

declare(strict_types=1);

namespace Aether;

use Illuminate\Support\ServiceProvider;

class AetherServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/aether.php',
            'aether'
        );

        $this->app->singleton(QuantumManager::class, function ($app): QuantumManager {
            return new QuantumManager($app);
        });

        // Bind as non-singleton: the QuantumManager handles driver caching internally.
        $this->app->bind(
            \Aether\Contracts\QuantumDevice::class,
            fn ($app): \Aether\Contracts\QuantumDevice => $app->make(\Aether\QuantumManager::class)->driver(),
        );
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/aether.php' => config_path('aether.php'),
            ], 'aether-config');

            $this->commands([Commands\AetherInstallCommand::class]);
        }
    }
}
