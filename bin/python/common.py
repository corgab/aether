#!/usr/bin/env python3
"""Shared utilities for Aether quantum scripts."""

from typing import Any


def resolve_device(driver: str, driver_config: dict[str, Any]) -> Any:
    """Resolve the Braket device from the driver name and its configuration.

    For ``"local"`` the Amazon Braket local simulator is returned directly.
    For ``"aws"`` a real :class:`~braket.aws.AwsDevice` is constructed using
    a :class:`boto3.Session` seeded from ``driver_config``.

    Args:
        driver:        Either ``"local"`` or ``"aws"``.
        driver_config: Driver-specific options (region, device_arn, ...).

    Returns:
        A Braket device object (either a local simulator or an AwsDevice).

    Raises:
        ValueError: When ``driver`` is not a recognised value.
    """
    if driver == "local":
        from braket.devices import LocalSimulator  # noqa: PLC0415

        return LocalSimulator()

    if driver == "aws":
        import boto3  # noqa: PLC0415
        from braket.aws import AwsDevice, AwsSession  # noqa: PLC0415

        region = driver_config.get("region", "us-east-1")
        boto_session = boto3.Session(region_name=region)
        aws_session = AwsSession(boto_session=boto_session)

        device_arn = driver_config.get(
            "device_arn",
            "arn:aws:braket:::device/quantum-simulator/amazon/sv1",
        )
        return AwsDevice(device_arn, aws_session=aws_session)

    raise ValueError(f"Unknown driver: {driver!r}")
