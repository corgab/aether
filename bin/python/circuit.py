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

from common import resolve_device


def _build_circuit(qubits: int, gates: list[dict[str, Any]]) -> "Circuit":
    """Build a Braket :class:`~braket.circuits.Circuit` from a gate list.

    Supported gate types: ``h``, ``x``, ``y``, ``z``, ``cnot``, ``measure``.
    For ``measure`` gates, a ``null`` / missing ``targets`` field means
    *measure all qubits* (indices 0 through ``qubits-1``).

    Args:
        qubits: Total number of qubits in the circuit.
        gates:  Ordered list of gate-definition dicts.

    Returns:
        A fully-constructed :class:`~braket.circuits.Circuit` instance.

    Raises:
        ValueError: When an unknown gate type or missing required key is
            encountered.
    """
    from braket.circuits import Circuit  # noqa: PLC0415

    circuit = Circuit()

    for gate in gates:
        gate_type = gate.get("type", "")

        if gate_type == "h":
            circuit.h(gate["target"])

        elif gate_type == "x":
            circuit.x(gate["target"])

        elif gate_type == "y":
            circuit.y(gate["target"])

        elif gate_type == "z":
            circuit.z(gate["target"])

        elif gate_type == "cnot":
            circuit.cnot(gate["control"], gate["target"])

        elif gate_type == "measure":
            targets = gate.get("targets")
            qubit_indices = targets if targets is not None else list(range(qubits))
            circuit.measure(qubit_indices)

        else:
            raise ValueError(f"Unknown gate type: {gate_type!r}")

    return circuit


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

    circuit = _build_circuit(qubits, gates)
    device = resolve_device(driver, driver_config)

    run_kwargs: dict[str, Any] = {"shots": shots}

    if driver == "aws":
        bucket = driver_config.get("bucket")
        if bucket:
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
