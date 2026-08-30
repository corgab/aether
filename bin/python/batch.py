#!/usr/bin/env python3
"""Batch circuit executor for the Aether Laravel package.

Reads a list of circuit definitions as JSON from stdin, builds every circuit,
runs them as a single Braket batch, and writes one measurement-count histogram
per circuit to stdout, in the same order as the input.

Input schema (JSON on stdin)::

    {
        "circuits": [
            {"qubits": 2, "gates": [...], "shots": 1000},
            {"qubits": 1, "gates": [...], "shots": 500}
        ],
        "driver": "local",
        "driver_config": {}
    }

Output (JSON on stdout)::

    {"results": [{"counts": {"00": 503, "11": 497}}, {"counts": {"1": 500}}]}

Shots are honoured per circuit. ``AwsDevice.run_batch`` accepts one shot count
per task natively; ``LocalSimulator.run_batch`` does not, so mixed shot counts
on the local driver fall back to running the circuits sequentially inside this
same process.

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import build_circuit, resolve_device


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    circuits_data: list[dict[str, Any]] = payload["circuits"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    if not circuits_data:
        return {"results": []}

    circuits = [build_circuit(c["qubits"], c["gates"]) for c in circuits_data]
    shots_list = [int(c.get("shots", 1000)) for c in circuits_data]

    device = resolve_device(driver, driver_config)

    if driver == "aws":
        bucket = driver_config.get("bucket")
        if not bucket:
            raise ValueError("Driver 'aws' requires a non-empty 'bucket' in driver_config.")

        batch = device.run_batch(
            circuits,
            shots=shots_list,
            s3_destination_folder=(bucket, "results"),
        )
        # Without fail_unsuccessful the SDK returns None for FAILED/CANCELLED
        # tasks; let it raise a clear RuntimeError instead.
        results = batch.results(fail_unsuccessful=True)
    elif len(set(shots_list)) == 1:
        results = device.run_batch(circuits, shots=shots_list[0]).results()
    else:
        results = [
            device.run(circuit, shots=shots).result()
            for circuit, shots in zip(circuits, shots_list)
        ]

    return {"results": [{"counts": dict(result.measurement_counts)} for result in results]}


def main() -> None:
    try:
        payload = json.loads(sys.stdin.read())
        print(json.dumps(_run(payload)))
    except Exception as exc:  # noqa: BLE001 - every failure must surface as JSON
        print(json.dumps({"error": str(exc)}), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
