#!/usr/bin/env python3
"""Built-in provider for AWS Braket QPUs and managed simulators.

Implements every optional hook of the provider contract on top of the
required ``resolve_device``: S3 result routing via ``run_options``, native
per-task shot counts via ``run_batch``, and ARN-based polling via
``check_task``.
"""

from importlib.metadata import PackageNotFoundError, version
from typing import Any

_DEFAULT_DEVICE_ARN = "arn:aws:braket:::device/quantum-simulator/amazon/sv1"

# ``AwsDevice.run_batch`` accepts one shot count per task from this release on;
# it is the floor declared in bin/python/requirements.txt.
_MIN_SDK_FOR_PER_TASK_SHOTS = (1, 125, 0)


def _installed_sdk_version() -> tuple[int, ...] | None:
    """Return the installed ``amazon-braket-sdk`` version, or None if unknown."""
    try:
        raw = version("amazon-braket-sdk")
    except PackageNotFoundError:
        return None

    parts = raw.split(".")[:3]
    if not all(part.isdigit() for part in parts):
        return None

    return tuple(int(part) for part in parts)


def _assert_per_task_shots_supported() -> None:
    """Fail with an upgrade hint instead of an opaque SDK error on old SDKs.

    An environment installed under an older requirements floor keeps passing
    every other check and would otherwise fail deep inside boto3 parameter
    validation when ``shots`` is a list.
    """
    installed = _installed_sdk_version()

    if installed is not None and installed < _MIN_SDK_FOR_PER_TASK_SHOTS:
        floor = ".".join(str(part) for part in _MIN_SDK_FOR_PER_TASK_SHOTS)
        raise RuntimeError(
            f"Batch execution on the 'aws' driver needs amazon-braket-sdk >= {floor} "
            f"(per-task shot counts in AwsDevice.run_batch); "
            f"{'.'.join(str(part) for part in installed)} is installed. "
            f"Upgrade with: pip install -r bin/python/requirements.txt"
        )


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
    Per-task shot counts require ``amazon-braket-sdk`` >= 1.125.0, which is
    the floor declared in ``bin/python/requirements.txt``.
    """
    _assert_per_task_shots_supported()

    batch = device.run_batch(circuits, shots=shots_list, **run_options(config))
    # Without fail_unsuccessful the SDK returns None for FAILED/CANCELLED
    # tasks; let it raise a clear RuntimeError instead.
    return batch.results(fail_unsuccessful=True)


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    """Return the current status of the Braket task *task_id* (an ARN).

    The ``status`` value is passed through verbatim from Braket (``CREATED``,
    ``QUEUED``, ``RUNNING``, ``COMPLETED``, ``FAILED``, ``CANCELLED``); a
    ``counts`` histogram is added once the task has completed.
    """
    from braket.aws import AwsQuantumTask  # noqa: PLC0415

    task = AwsQuantumTask(task_id, aws_session=build_aws_session(config))
    state = task.state()

    output: dict[str, Any] = {"status": state}

    if state == "COMPLETED":
        output["counts"] = dict(task.result().measurement_counts)

    return output
