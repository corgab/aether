<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Quantum Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default quantum computing driver that will be
    | used by the package. You may set this to any of the drivers defined
    | in the "drivers" array below.
    |
    */

    'default' => env('AETHER_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Python Executable Path
    |--------------------------------------------------------------------------
    |
    | The path to the Python executable used to run quantum scripts. This
    | allows support for virtual environments, custom installs, or
    | Windows systems where `python3` may not be available.
    |
    */

    'python_path' => env('AETHER_PYTHON_PATH', 'python3'),

    /*
    |--------------------------------------------------------------------------
    | Quantum Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure each quantum computing driver. The "local"
    | driver uses the Braket local simulator (no AWS costs). The "aws"
    | driver connects to AWS Braket for real quantum hardware.
    |
    */

    'drivers' => [

        'local' => [
            'backend' => 'default',
            'synchronous_safe' => true,
        ],

        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AETHER_S3_BUCKET'),
            'device_arn' => env('AETHER_DEVICE_ARN', 'arn:aws:braket:::device/quantum-simulator/amazon/sv1'),
            'synchronous_safe' => true,
        ],

    ],

];
