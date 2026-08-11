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
    | Python Process Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds a Python subprocess is allowed to run
    | before it is killed. Increase this if you run circuits with a large
    | number of shots or qubits that take longer than the default to
    | complete.
    |
    */

    'process_timeout' => (int) env('AETHER_PROCESS_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous Execution
    |--------------------------------------------------------------------------
    |
    | Circuits dispatched with ->dispatch() are submitted to the backend by a
    | queued job, then polled until the task reaches a terminal state. "queue"
    | selects the queue those jobs run on (null uses the default queue),
    | "poll_interval" is the delay in seconds between status checks, and
    | "max_poll_attempts" caps how long a task may stay in flight before the
    | polling job gives up (the default allows one hour at five-second
    | intervals, which comfortably covers real QPU queue times).
    |
    */

    'queue' => env('AETHER_QUEUE'),

    'poll_interval' => (int) env('AETHER_POLL_INTERVAL', 5),

    'max_poll_attempts' => (int) env('AETHER_MAX_POLL_ATTEMPTS', 720),

    /*
    |--------------------------------------------------------------------------
    | Local Task Retention
    |--------------------------------------------------------------------------
    |
    | The local simulator has no real task queue, so asynchronously submitted
    | circuits are executed immediately and their results are cached under a
    | synthetic task identifier. This is how long, in seconds, those results
    | stay available to the polling job.
    |
    */

    'local_task_ttl' => (int) env('AETHER_LOCAL_TASK_TTL', 3600),

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
            'synchronous_safe' => true,
            'entropy_qubits' => (int) env('AETHER_ENTROPY_QUBITS', 16),
        ],

        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AETHER_S3_BUCKET'),
            'device_arn' => env('AETHER_DEVICE_ARN', 'arn:aws:braket:::device/quantum-simulator/amazon/sv1'),
            'synchronous_safe' => true,
            'entropy_qubits' => (int) env('AETHER_ENTROPY_QUBITS', 16),
        ],

    ],

];
