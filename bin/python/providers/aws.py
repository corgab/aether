#!/usr/bin/env python3
"""Built-in provider for AWS Braket QPUs and managed simulators.

Implements every optional hook of the provider contract on top of the
required ``resolve_device``: S3 result routing via ``run_options``, native
per-task shot counts via ``run_batch``, and ARN-based polling via
``check_task``.
"""

from typing import Any

from common import require_result

_DEFAULT_DEVICE_ARN = "arn:aws:braket:::device/quantum-simulator/amazon/sv1"


def build_aws_session(config: dict[str, Any]) -> Any:
    """Construct an ``AwsSession`` seeded from the driver *config*.

    Factored out so device resolution (submit/run) and task polling
    (``check_task``) build the session identically, from a single
    implementation.

    Args:
        config: Driver-specific options (currently just ``region``).

    Returns:
        A configured :class:`~braket.aws.AwsSession` instance.
    """
    import boto3  # noqa: PLC0415
    from braket.aws import AwsSession  # noqa: PLC0415

    region = config.get("region", "us-east-1")
    boto_session = boto3.Session(region_name=region)
    return AwsSession(boto_session=boto_session)


def resolve_device(config: dict[str, Any]) -> Any:
    """Return an :class:`~braket.aws.AwsDevice` for the configured ARN."""
    from braket.aws import AwsDevice  # noqa: PLC0415

    device_arn = config.get("device_arn", _DEFAULT_DEVICE_ARN)
    return AwsDevice(device_arn, aws_session=build_aws_session(config))


def run_options(config: dict[str, Any]) -> dict[str, Any]:
    """Return the extra ``device.run()`` kwargs: the S3 destination folder.

    Raises:
        ValueError: When *config* has no non-empty ``bucket``.
    """
    bucket = config.get("bucket")
    if not bucket:
        raise ValueError("Driver 'aws' requires a non-empty 'bucket' in driver_config.")

    return {"s3_destination_folder": (bucket, "results")}


def run_batch(
    device: Any,
    circuits: list[Any],
    shots_list: list[int],
    config: dict[str, Any],
) -> list[Any]:
    """Run *circuits* as a single Braket batch with per-task shot counts.

    ``AwsDevice.run_batch`` accepts one shot count per task natively, so the
    whole batch always goes through in one call regardless of mixed shots.

    Raises:
        RuntimeError: When a task in the batch ended without a result, naming
            that task and the ``failureReason`` Braket reported for it. The
            SDK's own ``fail_unsuccessful`` flag would only say how many tasks
            failed, so the pairing is done here instead.
    """
    batch = device.run_batch(circuits, shots=shots_list, **run_options(config))
    # results() retries unsuccessful tasks and swaps them into batch.tasks,
    # so the tasks are read only once the results are final.
    results = batch.results()

    return [require_result(task, result) for task, result in zip(batch.tasks, results)]


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    """Return the current status of the Braket task *task_id* (an ARN).

    The ``status`` value is passed through verbatim from Braket (``CREATED``,
    ``QUEUED``, ``RUNNING``, ``COMPLETED``, ``FAILED``, ``CANCELLED``); a
    ``counts`` histogram is added once the task has completed, and an
    ``error`` string when the task ended as ``FAILED`` or ``CANCELLED`` and
    Braket reported a ``failureReason`` for it.
    """
    from braket.aws import AwsQuantumTask  # noqa: PLC0415

    task = AwsQuantumTask(task_id, aws_session=build_aws_session(config))
    state = task.state()

    output: dict[str, Any] = {"status": state}

    if state == "COMPLETED":
        output["counts"] = dict(task.result().measurement_counts)

    if state in ("FAILED", "CANCELLED"):
        # state() has just fetched GetQuantumTask; reuse that response.
        reason = task.metadata(use_cached_value=True).get("failureReason")

        if reason:
            output["error"] = str(reason)

    return output
