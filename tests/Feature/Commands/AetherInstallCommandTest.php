<?php

declare(strict_types=1);

use Aether\Commands\AetherInstallCommand;
use Aether\Facades\Quantum;
use Aether\QuantumManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Bind a QuantumManager double whose driver('local') call always throws,
 * simulating a failed test circuit without depending on a real Python/braket
 * install.
 */
function bindFailingQuantumManager(): void
{
    $manager = Mockery::mock(QuantumManager::class);
    $manager->shouldReceive('driver')->with('local')->andThrow(new RuntimeException('boom'));

    app()->instance(QuantumManager::class, $manager);
}

/**
 * Create a throwaway executable that stands in for the python interpreter
 * aether:install shells out to. Answers `--version` with "Python 3.12.0" on
 * stdout, and `-c` (the amazon-braket-sdk version probe) with the given
 * stdout/exit code so checkDependencies()'s branches can be exercised
 * without a real Python/braket environment.
 */
function fakeInstallInterpreter(string $probeOutput, int $probeExitCode = 0): string
{
    $path = tempnam(sys_get_temp_dir(), 'aether_install_fakepy_');
    $body = <<<SH
        #!/bin/sh
        case "\$1" in
          --version) echo "Python 3.12.0" ;;
          -c) echo "{$probeOutput}"; exit {$probeExitCode} ;;
        esac
        SH;
    file_put_contents($path, $body."\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * Expose AetherInstallCommand::parseRequirementsFloor() (protected) for
 * direct unit-style testing of the parsing logic.
 */
final class RequirementsFloorProbe extends AetherInstallCommand
{
    public static function floor(string $contents): ?string
    {
        return parent::parseRequirementsFloor($contents);
    }
}

// The test circuit is faked by default so these tests don't depend on a real
// Python/braket environment being available on the machine running them.
// config_path('aether.php') is also cleared before each test so publishConfig()
// behaves as it would against a fresh application, regardless of what a
// previous test run in this environment may have published there.
beforeEach(function () {
    Quantum::fake();

    if (file_exists(config_path('aether.php'))) {
        unlink(config_path('aether.php'));
    }
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

// -------------------------------------------------------------------------
// Issue #40 — config overwritten without confirmation
// -------------------------------------------------------------------------

it('keeps an existing config file when no --force is given', function () {
    file_put_contents(config_path('aether.php'), "<?php\n\nreturn ['marker' => 'kept'];\n");

    try {
        $this->artisan('aether:install', ['--no-interaction' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('KEPT');

        expect(file_get_contents(config_path('aether.php')))->toContain('marker');
    } finally {
        @unlink(config_path('aether.php'));
    }
});

it('overwrites an existing config file when --force is given', function () {
    file_put_contents(config_path('aether.php'), "<?php\n\nreturn ['marker' => 'kept'];\n");

    try {
        $this->artisan('aether:install', ['--no-interaction' => true, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('PUBLISHED');

        expect(file_get_contents(config_path('aether.php')))->not->toContain('marker');
    } finally {
        @unlink(config_path('aether.php'));
    }
});

it('publishes the config file when it does not exist yet', function () {
    expect(file_exists(config_path('aether.php')))->toBeFalse();

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('PUBLISHED');
});

it('asks for confirmation and keeps the config file when the user declines to overwrite', function () {
    file_put_contents(config_path('aether.php'), "<?php\n\nreturn ['marker' => 'kept'];\n");

    // Reports amazon-braket-sdk as already installed and up to date, so the
    // (also interactive) "create a virtual environment?" question never
    // comes up — this test is only about the config confirmation.
    $floor = RequirementsFloorProbe::floor(
        (string) file_get_contents(__DIR__.'/../../../bin/python/requirements.txt')
    );
    config(['aether.python_path' => fakeInstallInterpreter($floor)]);

    try {
        $this->artisan('aether:install')
            ->expectsConfirmation('config/aether.php already exists. Overwrite it with the package default?', 'no')
            ->assertSuccessful()
            ->expectsOutputToContain('KEPT');

        expect(file_get_contents(config_path('aether.php')))->toContain('marker');
    } finally {
        @unlink(config_path('aether.php'));
    }
});

// -------------------------------------------------------------------------
// Issue #71 — braket detection
// -------------------------------------------------------------------------

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/aether_install_fakepy_*') ?: [] as $tmp) {
        @unlink($tmp);
    }
});

it('reports amazon-braket-sdk as installed when the version meets the required floor', function () {
    $floor = RequirementsFloorProbe::floor(
        (string) file_get_contents(__DIR__.'/../../../bin/python/requirements.txt')
    );

    config(['aether.python_path' => fakeInstallInterpreter($floor)]);

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain($floor)
        ->doesntExpectOutputToContain('NOT INSTALLED');
});

it('warns when the installed amazon-braket-sdk version is older than the required floor', function () {
    $floor = RequirementsFloorProbe::floor(
        (string) file_get_contents(__DIR__.'/../../../bin/python/requirements.txt')
    );

    config(['aether.python_path' => fakeInstallInterpreter('1.0.0')]);

    // Uses Artisan::call()/output() instead of $this->artisan()->expectsOutputToContain():
    // that assertion checks each write() call individually, and the
    // terminal-width-aware rendering of components->twoColumnDetail() can
    // word-wrap this long value across more than one write, splitting the
    // floor version across two chunks and making a substring check flake.
    $exitCode = Artisan::call('aether:install', ['--no-interaction' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS);
    expect($output)->toContain('requires');
    expect($output)->toContain($floor);
});

it('reports amazon-braket-sdk as not installed when the probe fails', function () {
    config(['aether.python_path' => fakeInstallInterpreter('', 1)]);

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('NOT INSTALLED')
        ->expectsOutputToContain('python3 -m venv .aether-venv');
});

it('parses the amazon-braket-sdk floor from a requirements.txt-shaped string', function () {
    expect(RequirementsFloorProbe::floor("amazon-braket-sdk>=1.80.0\nnumpy>=2.5.2\n"))->toBe('1.80.0');
    expect(RequirementsFloorProbe::floor("amazon-braket-sdk >= 2.0.0\n"))->toBe('2.0.0');
    expect(RequirementsFloorProbe::floor("numpy>=2.5.2\n"))->toBeNull();
});

// -------------------------------------------------------------------------
// Issue #41 — smoke test never touches the configured default driver
// -------------------------------------------------------------------------

it('runs the smoke test on the local simulator even when the default driver is aws', function () {
    config(['aether.default' => 'aws']);

    $this->artisan('aether:install', ['--no-interaction' => true])
        ->assertSuccessful();

    Quantum::assertCircuitRan(fn ($circuit) => $circuit->driverName() === 'local');
});
