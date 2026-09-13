<?php

declare(strict_types=1);

namespace Aether\Commands;

use Illuminate\Console\Command;

/**
 * Install and configure the Aether quantum computing package.
 */
class AetherInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aether:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure the Aether quantum computing package';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing Aether...');

        $this->publishConfig();

        $this->components->info('Aether installation complete.');

        return self::SUCCESS;
    }

    /**
     * Publish the Aether configuration file.
     */
    protected function publishConfig(): void
    {
        $this->call('vendor:publish', [
            '--tag' => 'aether-config',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('Config file', '<fg=green>PUBLISHED</>');
    }
}
