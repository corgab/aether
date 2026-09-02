#!/usr/bin/env python3
"""Asynchronous quantum task poller for the Aether Laravel package.

Reads a previously-submitted task identifier as JSON from stdin, checks its
current status through the driver's provider module, and writes the status
(plus the result once completed) as JSON to stdout. Used by a queued job to
poll long-running QPU tasks submitted via ``submit.py`` without blocking on
``task.result()``.

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

Status values must be one of ``CREATED``, ``QUEUED``, ``RUNNING``,
``COMPLETED``, ``FAILED``, ``CANCELLED`` (the PHP ``TaskStatus`` enum); the
aws provider passes them through verbatim from Braket.

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import load_provider


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Check the status of a previously-submitted task and return it.

    Args:
        payload: Deserialised JSON input dict.

    Returns:
        A dict with a ``"status"`` key, plus a ``"counts"`` key when the
        task has completed.

    Raises:
        ValueError: When no provider resolves for ``driver``, or when the
            resolved provider does not define a ``check_task`` hook — the
            local simulator, for instance, runs synchronously and has no
            task polling support (LocalSimulatorDriver handles local async
            execution itself, so check.py is never called with it).
    """
    task_arn: str = payload["task_arn"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    provider = load_provider(driver, driver_config)
    check_task = getattr(provider, "check_task", None)

    if not callable(check_task):
        raise ValueError(f"Driver {driver!r} does not support task polling.")

    return check_task(task_arn, driver_config)


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
