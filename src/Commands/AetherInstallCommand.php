<?php

declare(strict_types=1);

namespace Aether\Commands;

use Aether\QuantumManager;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

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
    public function handle(QuantumManager $manager): int
    {
        $this->components->info('Installing Aether...');

        $this->publishConfig();

        $pythonPath = (string) config('aether.python_path', 'python3');

        $pythonOk = $this->checkPython($pythonPath);

        if ($pythonOk) {
            $depsOk = $this->checkDependencies($pythonPath);

            if (! $depsOk) {
                $this->handleMissingDependencies($pythonPath);
            }
        }

        $this->suggestGitignore();

        if ($pythonOk && ! $this->runTestCircuit($manager)) {
            $this->components->warn(
                'The test circuit failed to run. Check that your Python dependencies are '
                .'installed correctly, or that AETHER_PYTHON_PATH points to a valid interpreter.'
            );

            return self::FAILURE;
        }

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

    /**
     * Check whether Python is available at the given path.
     */
    protected function checkPython(string $pythonPath): bool
    {
        $process = new Process([$pythonPath, '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput() ?: $process->getErrorOutput());
            $this->components->twoColumnDetail('Python', "<fg=green>{$version}</>");

            return true;
        }

        $this->components->twoColumnDetail('Python', '<fg=red>NOT FOUND</>');
        $this->components->warn(
            "Python was not found at [{$pythonPath}]. "
            .'Set AETHER_PYTHON_PATH in your .env to the correct executable.'
        );

        return false;
    }

    /**
     * Check whether the required Python dependencies (braket) are installed.
     */
    protected function checkDependencies(string $pythonPath): bool
    {
        $process = new Process([
            $pythonPath, '-c', 'import braket; print(braket.__version__)',
        ]);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            $this->components->twoColumnDetail('amazon-braket-sdk', "<fg=green>{$version}</>");

            return true;
        }

        $this->components->twoColumnDetail('amazon-braket-sdk', '<fg=yellow>NOT INSTALLED</>');

        return false;
    }

    /**
     * Handle missing Python dependencies.
     */
    protected function handleMissingDependencies(string $pythonPath): void
    {
        if (! $this->input->isInteractive()) {
            $this->showManualInstructions();

            return;
        }

        $createVenv = $this->components->confirm(
            'Would you like Aether to create a virtual environment and install dependencies automatically?'
        );

        if ($createVenv) {
            $this->createVenv($pythonPath);
        } else {
            $this->showManualInstructions();
        }
    }

    /**
     * Check whether .aether-venv is excluded from version control.
     */
    protected function suggestGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');

        if (! file_exists($gitignorePath)) {
            return;
        }

        $contents = (string) file_get_contents($gitignorePath);

        if (! str_contains($contents, '.aether-venv')) {
            $this->components->warn(
                'Add <fg=yellow>.aether-venv</> to your .gitignore to avoid committing the virtual environment.'
            );
        }
    }

    /**
     * Run a minimal test circuit to verify the installation end-to-end.
     */
    protected function runTestCircuit(QuantumManager $manager): bool
    {
        $succeeded = false;

        // components->task() renders the DONE/FAIL line but does not return
        // the callback's result, so it is captured via reference instead.
        $this->components->task('Running test circuit', function () use ($manager, &$succeeded): bool {
            try {
                $manager->circuit()->qubits(1)->h(0)->measure()->run();
                $succeeded = true;
            } catch (Throwable) {
                $succeeded = false;
            }

            return $succeeded;
        });

        return $succeeded;
    }

    /**
     * Create a Python virtual environment and install Aether's dependencies.
     */
    protected function createVenv(string $pythonPath): void
    {
        $venvPath = base_path('.aether-venv');
        $venvPython = $this->venvPythonPath($venvPath);
        $requirementsPath = __DIR__.'/../../bin/python/requirements.txt';

        $this->components->task('Creating virtual environment', function () use ($pythonPath, $venvPath): bool {
            $process = new Process([$pythonPath, '-m', 'venv', $venvPath]);
            $process->run();

            return $process->isSuccessful();
        });

        $this->components->task('Installing Python dependencies', function () use ($venvPython, $requirementsPath): bool {
            $process = new Process([$venvPython, '-m', 'pip', 'install', '-r', $requirementsPath]);
            $process->setTimeout(300);
            $process->run();

            return $process->isSuccessful();
        });

        $this->components->twoColumnDetail(
            'Add to <fg=cyan>.env</>',
            "<fg=green>AETHER_PYTHON_PATH={$venvPython}</>"
        );

        $this->components->warn(
            "Never commit your .env file. Add the following line manually:\n"
            ."  AETHER_PYTHON_PATH={$venvPython}"
        );
    }

    /**
     * Display manual installation instructions for Python dependencies.
     */
    protected function showManualInstructions(): void
    {
        $requirementsPath = __DIR__.'/../../bin/python/requirements.txt';

        $this->components->warn('Manual installation required. Run the following commands:');
        $this->line('');
        $this->line('  python3 -m venv .aether-venv');
        $this->line('  .aether-venv/bin/pip install -r '.$requirementsPath);
        $this->line('');
        $this->line('Then add to your <fg=cyan>.env</>:');
        $this->line('  AETHER_PYTHON_PATH='.base_path('.aether-venv').'/bin/python');
        $this->line('');
    }

    /**
     * Return the Python executable path inside a virtual environment.
     */
    protected function venvPythonPath(string $venvPath): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $venvPath.'\\Scripts\\python.exe';
        }

        return $venvPath.'/bin/python';
    }
}
