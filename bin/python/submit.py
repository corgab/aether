#!/usr/bin/env python3
"""Asynchronous quantum circuit submitter for the Aether Laravel package.

Reads a circuit definition as JSON from stdin, builds a Braket circuit, and
submits it for execution *without* waiting for a result. This is used for
QPU tasks that may queue for hours — the PHP side stores the returned task
ARN and polls it later via ``check.py`` from a queued job.

Input schema (JSON on stdin)::

    {
        "qubits": 2,
        "gates": [
            {"type": "h",     "target": 0},
            {"type": "cnot",  "control": 0, "target": 1},
            {"type": "measure", "targets": [0, 1]}
        ],
        "shots": 1000,
        "driver": "aws",
        "driver_config": {}
    }

Output (JSON on stdout)::

    {"task_arn": "arn:aws:braket:us-east-1:...:quantum-task/uuid"}

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import build_circuit, resolve_device


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Submit the circuit described by *payload* and return its task ARN.

    Unlike ``circuit.py``, this does not call ``task.result()`` — it returns
    as soon as the task has been accepted by the device, so it never blocks
    on a queued QPU task.

    Args:
        payload: Deserialised JSON input dict.

    Returns:
        A dict with a single ``"task_arn"`` key holding the submitted task's
        identifier (a Braket ARN for ``"aws"``, a local UUID for ``"local"``).
    """
    qubits: int = payload["qubits"]
    gates: list[dict[str, Any]] = payload["gates"]
    shots: int = payload["shots"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    circuit = build_circuit(qubits, gates)
    device = resolve_device(driver, driver_config)

    run_kwargs: dict[str, Any] = {"shots": shots}

    if driver == "aws":
        bucket = driver_config.get("bucket")
        if bucket:
            run_kwargs["s3_destination_folder"] = (bucket, "results")

    task = device.run(circuit, **run_kwargs)

    return {"task_arn": task.id}


def main() -> None:
    """Entry point: read JSON from stdin, submit the circuit, write JSON to stdout."""
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
