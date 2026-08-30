#!/usr/bin/env python3
"""Quantum circuit executor for the Aether Laravel package.

Reads a circuit definition as JSON from stdin, builds and runs an Amazon
Braket circuit, then writes the measurement-count histogram as JSON to stdout.

Input schema (JSON on stdin)::

    {
        "qubits": 2,
        "gates": [
            {"type": "h",     "target": 0},
            {"type": "cnot",  "control": 0, "target": 1},
            {"type": "measure", "targets": [0, 1]}
        ],
        "shots": 1000,
        "driver": "local",
        "driver_config": {}
    }

Output (JSON on stdout)::

    {"counts": {"00": 503, "11": 497}}

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import build_circuit, resolve_device


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Execute the circuit described by *payload* and return the count histogram.

    Args:
        payload: Deserialised JSON input dict.

    Returns:
        A dict with a single ``"counts"`` key mapping bitstrings to integers.
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
        if not bucket:
            raise ValueError("Driver 'aws' requires a non-empty 'bucket' in driver_config.")

        run_kwargs["s3_destination_folder"] = (bucket, "results")

    task = device.run(circuit, **run_kwargs)
    result = task.result()

    # measurement_counts is a Counter-like dict {bitstring: int}
    counts = dict(result.measurement_counts)

    return {"counts": counts}


def main() -> None:
    """Entry point: read JSON from stdin, run the circuit, write JSON to stdout."""
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
