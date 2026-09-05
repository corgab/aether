<?php

declare(strict_types=1);

namespace Aether\Commands;

use Aether\Bridge\PythonBridge;
use Aether\Circuit\CircuitBuilder;
use Aether\Contracts\QuantumDevice;
use Aether\Drivers\LocalSimulatorDriver;
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
    protected $signature = 'aether:install {--force : Overwrite an existing config/aether.php}';

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
        $venvPython = null;

        if ($pythonOk) {
            $depsOk = $this->checkDependencies($pythonPath);

            if (! $depsOk) {
                $venvPython = $this->handleMissingDependencies($pythonPath);
            }
        }

        $this->suggestGitignore();

        if ($pythonOk && ! $this->verifyInstallation($manager, $venvPython)) {
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
     *
     * An existing config/aether.php is kept unless --force is given. When
     * kept, and the input is interactive, the user is asked whether to
     * overwrite it; a non-interactive run keeps it without asking.
     */
    protected function publishConfig(): void
    {
        $configExists = file_exists(config_path('aether.php'));

        $shouldPublish = ! $configExists || $this->option('force');

        if ($configExists && ! $this->option('force') && $this->input->isInteractive()) {
            $shouldPublish = $this->components->confirm(
                'config/aether.php already exists. Overwrite it with the package default?',
                false
            );
        }

        if (! $shouldPublish) {
            $this->components->twoColumnDetail('Config file', '<fg=yellow>KEPT (pass --force to overwrite)</>');

            return;
        }

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
     * Check whether the required Python dependencies (amazon-braket-sdk) are
     * installed, and whether the installed version meets the floor pinned in
     * bin/python/requirements.txt.
     *
     * braket itself is a namespace package with no __version__ attribute, so
     * the probe reads the installed distribution's version through
     * importlib.metadata instead of importing braket directly.
     */
    protected function checkDependencies(string $pythonPath): bool
    {
        $process = new Process([
            $pythonPath, '-c', 'import importlib.metadata as m; print(m.version("amazon-braket-sdk"))',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->components->twoColumnDetail('amazon-braket-sdk', '<fg=yellow>NOT INSTALLED</>');

            return false;
        }

        $version = trim($process->getOutput());
        $floor = static::parseRequirementsFloor((string) file_get_contents(self::requirementsPath()));

        if ($floor !== null && ! version_compare($version, $floor, '>=')) {
            $this->components->twoColumnDetail(
                'amazon-braket-sdk',
                "<fg=yellow>{$version} (requires >= {$floor})</>"
            );

            $this->components->warn(
                'An upgrade is available. Run: pip install --upgrade -r '.self::requirementsPath()
            );

            return true;
        }

        $this->components->twoColumnDetail('amazon-braket-sdk', "<fg=green>{$version}</>");

        return true;
    }

    /**
     * Parse the minimum amazon-braket-sdk version pinned in a
     * requirements.txt file's contents, or null when no floor is pinned.
     */
    protected static function parseRequirementsFloor(string $contents): ?string
    {
        if (preg_match('/^amazon-braket-sdk\s*>=\s*([\d.]+)/m', $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Handle missing Python dependencies.
     *
     * Returns the venv interpreter path when a virtual environment was
     * created and its dependencies installed successfully, otherwise null.
     */
    protected function handleMissingDependencies(string $pythonPath): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->showManualInstructions();

            return null;
        }

        $createVenv = $this->components->confirm(
            'Would you like Aether to create a virtual environment and install dependencies automatically?'
        );

        if ($createVenv) {
            return $this->createVenv($pythonPath);
        }

        $this->showManualInstructions();

        return null;
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
     * Resolve the local-simulator device to run the smoke test through, and
     * run it. Never resolves the configured default driver — through the
     * venv interpreter when createVenv() just produced one, otherwise
     * through the local driver directly — so it never submits a billable
     * task even when AETHER_DRIVER is set to 'aws'.
     *
     * Resolving the device is wrapped in the same failure handling as the
     * test circuit itself: a device that fails to resolve counts as a
     * failed smoke test rather than an uncaught exception.
     */
    protected function verifyInstallation(QuantumManager $manager, ?string $venvPython): bool
    {
        try {
            $device = $venvPython !== null
                ? new LocalSimulatorDriver(
                    new PythonBridge($venvPython, (int) config('aether.process_timeout', 300)),
                    (array) config('aether.drivers.local', []),
                )
                : $manager->driver('local');
        } catch (Throwable) {
            return false;
        }

        return $this->runTestCircuit($device);
    }

    /**
     * Run a minimal test circuit on the local simulator to verify the
     * installation end-to-end.
     *
     * This never touches the configured default driver — it verifies the
     * Python bridge, nothing else — so it never submits a billable task even
     * when AETHER_DRIVER is set to 'aws'.
     */
    protected function runTestCircuit(QuantumDevice $device): bool
    {
        $succeeded = false;

        // components->task() renders the DONE/FAIL line but does not return
        // the callback's result, so it is captured via reference instead.
        $this->components->task('Running test circuit', function () use ($device, &$succeeded): bool {
            try {
                (new CircuitBuilder($device, 'local'))->qubits(1)->h(0)->measure()->run();
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
     *
     * Returns the venv's interpreter path when both steps succeeded, so the
     * caller can run the rest of the installation through it instead of the
     * interpreter that was configured when the process started; returns null
     * when either step failed.
     */
    protected function createVenv(string $pythonPath): ?string
    {
        $venvPath = base_path('.aether-venv');
        $venvPython = $this->venvPythonPath($venvPath);
        $requirementsPath = self::requirementsPath();

        $venvCreated = false;

        $this->components->task('Creating virtual environment', function () use ($pythonPath, $venvPath, &$venvCreated): bool {
            $process = new Process([$pythonPath, '-m', 'venv', $venvPath]);
            $process->run();

            $venvCreated = $process->isSuccessful();

            return $venvCreated;
        });

        $depsInstalled = false;

        $this->components->task('Installing Python dependencies', function () use ($venvPython, $requirementsPath, &$depsInstalled): bool {
            $process = new Process([$venvPython, '-m', 'pip', 'install', '-r', $requirementsPath]);
            $process->setTimeout(300);
            $process->run();

            $depsInstalled = $process->isSuccessful();

            return $depsInstalled;
        });

        if (! $venvCreated || ! $depsInstalled) {
            $this->components->warn(
                'The virtual environment could not be prepared. Fix the error above, or follow the manual steps:'
            );
            $this->showManualInstructions();

            return null;
        }

        $this->components->twoColumnDetail(
            'Add to <fg=cyan>.env</>',
            "<fg=green>AETHER_PYTHON_PATH={$venvPython}</>"
        );

        $this->components->warn(
            "Never commit your .env file. Add the following line manually:\n"
            ."  AETHER_PYTHON_PATH={$venvPython}"
        );

        return $venvPython;
    }

    /**
     * Display manual installation instructions for Python dependencies.
     */
    protected function showManualInstructions(): void
    {
        $requirementsPath = self::requirementsPath();

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

    /**
     * Return the absolute path to the package's bin/python/requirements.txt.
     */
    private static function requirementsPath(): string
    {
        return __DIR__.'/../../bin/python/requirements.txt';
    }
}
