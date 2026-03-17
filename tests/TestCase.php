<?php

declare(strict_types=1);

namespace Aether\Tests;

use Aether\AetherServiceProvider;
use Aether\Facades\Quantum;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register package service providers for the test suite.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AetherServiceProvider::class,
        ];
    }

    /**
     * Register package facade aliases for the test suite.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Quantum' => Quantum::class,
        ];
    }
}
