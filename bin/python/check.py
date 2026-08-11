#!/usr/bin/env python3
"""Asynchronous quantum task poller for the Aether Laravel package.

Reads a previously-submitted task ARN as JSON from stdin, checks its current
status on AWS Braket, and writes the status (plus the result once completed)
as JSON to stdout. Used by a queued job to poll long-running QPU tasks
submitted via ``submit.py`` without blocking on ``task.result()``.

Input schema (JSON on stdin)::

    {
        "task_arn": "arn:aws:braket:us-east-1:...:quantum-task/uuid",
        "driver": "aws",
        "driver_config": {}
    }

Output (JSON on stdout)::

    {"status": "RUNNING"}

or, once the task has finished::

    {"status": "COMPLETED", "counts": {"00": 503, "11": 497}}

Status values are passed through verbatim from Braket: ``CREATED``,
``QUEUED``, ``RUNNING``, ``COMPLETED``, ``FAILED``, ``CANCELLED``.

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import build_aws_session


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Check the status of a previously-submitted task and return it.

    Args:
        payload: Deserialised JSON input dict.

    Returns:
        A dict with a ``"status"`` key, plus a ``"counts"`` key when the
        task has completed.

    Raises:
        ValueError: When ``driver`` is ``"local"`` — the local simulator runs
            synchronously and has no ARN-based polling support — or when
            ``driver`` is not a recognised value.
    """
    task_arn: str = payload["task_arn"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    if driver == "local":
        raise ValueError(
            "The local simulator does not support ARN-based polling; "
            "LocalSimulatorDriver handles local async execution itself, "
            "so check.py should never be called with driver 'local'."
        )

    if driver != "aws":
        raise ValueError(f"Unknown driver: {driver!r}")

    from braket.aws import AwsQuantumTask  # noqa: PLC0415

    aws_session = build_aws_session(driver_config)
    task = AwsQuantumTask(task_arn, aws_session=aws_session)
    state = task.state()

    output: dict[str, Any] = {"status": state}

    if state == "COMPLETED":
        result = task.result()
        output["counts"] = dict(result.measurement_counts)

    return output


def main() -> None:
    """Entry point: read JSON from stdin, check the task, write JSON to stdout."""
    try:
        raw = sys.stdin.read()
        payload = json.loads(raw)
        output = _run(payload)
        print(json.dumps(output))
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"error": str(exc)}), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
