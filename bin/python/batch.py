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

Shots are honoured per circuit. Providers with a ``run_batch`` hook (the aws
provider uses ``AwsDevice.run_batch``, which accepts one shot count per task
natively) control batching themselves; for the rest the default strategy runs
one ``device.run_batch`` call for uniform shot counts and falls back to
running the circuits sequentially inside this same process for mixed ones.

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.
"""

import json
import sys
from typing import Any

from common import build_circuit, default_run_batch, load_provider, provider_run_options


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    circuits_data: list[dict[str, Any]] = payload["circuits"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    if not circuits_data:
        return {"results": []}

    circuits = [build_circuit(c["qubits"], c["gates"]) for c in circuits_data]
    shots_list = [int(c.get("shots", 1000)) for c in circuits_data]

    provider = load_provider(driver, driver_config)
    device = provider.resolve_device(driver_config)

    run_batch = getattr(provider, "run_batch", None)

    if callable(run_batch):
        results = run_batch(device, circuits, shots_list, driver_config)
    else:
        results = default_run_batch(
            device,
            circuits,
            shots_list,
            provider_run_options(provider, driver_config),
        )

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
