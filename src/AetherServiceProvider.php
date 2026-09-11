<?php

declare(strict_types=1);

namespace Aether;

use Aether\Config\AetherConfig;
use Aether\Contracts\QuantumDevice;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class AetherServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/aether.php',
            'aether'
        );

        $this->app->singleton(AetherConfig::class, function ($app): AetherConfig {
            return new AetherConfig($app->make(Repository::class));
        });

        $this->app->singleton(QuantumManager::class, function ($app): QuantumManager {
            return new QuantumManager($app);
        });

        // Bind as non-singleton: the QuantumManager handles driver caching internally.
        $this->app->bind(
            QuantumDevice::class,
            fn ($app): QuantumDevice => $app->make(QuantumManager::class)->driver(),
        );
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/aether.php' => config_path('aether.php'),
            ], 'aether-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'aether-migrations');

            $this->commands([Commands\AetherInstallCommand::class]);
        }

        AboutCommand::add('Aether', function (): array {
            $config = $this->app->make(AetherConfig::class);

            return [
                'Default Driver' => $config->defaultDriver(),
                'Python Path' => $config->pythonPath(),
                'Process Timeout' => $config->processTimeout().'s',
            ];
        });
    }
}
