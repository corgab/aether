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
    | Persist Asynchronous Tasks
    |--------------------------------------------------------------------------
    |
    | When enabled, Aether will record every asynchronously dispatched task
    | into a `quantum_tasks` database table, updating its status and counts
    | as the polling job progresses. You must run the published migration
    | before enabling this feature.
    |
    */

    'persist_tasks' => env('AETHER_PERSIST_TASKS', false),

    /*
    |--------------------------------------------------------------------------
    | Quantum Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure each quantum computing driver. The "local"
    | driver uses the Braket local simulator (no AWS costs). The "aws"
    | driver connects to AWS Braket for real quantum hardware.
    |
    | Any driver may declare an optional "python_provider" key pointing at a
    | Python provider module — either a filesystem path to a ".py" file or
    | an importable module name — that resolves the backend device on the
    | Python side. See the "Custom Providers" section of the README for the
    | provider contract. Security note: the referenced module is executed by
    | the Python subprocess with the same privileges as "python_path", so
    | this value is trusted configuration. Never derive it from user input.
    |
    */

    'drivers' => [

        'local' => [
            'synchronous_safe' => true,

            // The number of qubits (random bits) produced per shot of the
            // entropy circuit. Must fit within max_qubits below.
            'entropy_qubits' => (int) env('AETHER_ENTROPY_QUBITS', 16),

            // The local simulator keeps a full statevector in memory: a dense
            // vector of 2^n complex128 amplitudes, 16 bytes each, so memory
            // use doubles with every additional qubit. The default of 25
            // caps that at 2^25 x 16 bytes ~= 512 MB. Raise it only once
            // you've confirmed the host has memory to spare, or set it to
            // null to remove the ceiling entirely. Applies to ->run(),
            // ->dispatch(), Quantum::batch() and entropy generation alike.
            'max_qubits' => env('AETHER_MAX_QUBITS', 25),
        ],

        'aws' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AETHER_S3_BUCKET'),
            'device_arn' => env('AETHER_DEVICE_ARN', 'arn:aws:braket:::device/quantum-simulator/amazon/sv1'),
            'synchronous_safe' => true,

            // The number of qubits (random bits) produced per shot of the
            // entropy circuit. Must fit within max_qubits below; each
            // generate() call is one task whose estimated cost is checked
            // against max_cost_per_run.
            'entropy_qubits' => (int) env('AETHER_ENTROPY_QUBITS', 16),

            // No ceiling here: Braket enforces its own per-device qubit
            // limits, so this package does not duplicate or guess at those.
            'max_qubits' => null,

            // These mirror AWS Braket QPU list prices (per task + per shot)
            // at the time of writing — verify against current AWS pricing
            // before relying on them for budgeting. Managed simulators
            // (e.g. SV1) bill per-minute instead, but the task+shot model
            // is what estimateCost() covers; treat simulator estimates as
            // a rough proxy, not an exact figure. Override via env/config
            // without a package release.
            'pricing' => [
                'per_task' => (float) env('AETHER_AWS_PRICE_PER_TASK', 0.30),
                'per_shot' => (float) env('AETHER_AWS_PRICE_PER_SHOT', 0.00035),
                'currency' => env('AETHER_AWS_PRICE_CURRENCY', 'USD'),
            ],

            // When set, ->run(), ->dispatch(), Quantum::batch() and entropy
            // generation reject a run whose estimated cost exceeds this
            // amount — for a batch, the total of all its circuits — before
            // any AWS call is made. null (default) means unlimited. Requires
            // the pricing rates above: a ceiling with no rates fails fast
            // instead of never tripping.
            'max_cost_per_run' => env('AETHER_AWS_MAX_COST'),
        ],

    ],

];
