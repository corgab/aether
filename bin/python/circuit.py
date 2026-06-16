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

# Gate parameter keys that hold a qubit index (``measure`` is handled
# separately because its targets live in a list). Keep in sync with the gate
# branches in _build_circuit(); an allowlist is used deliberately rather than
# "every int parameter" because params such as ``angle`` may deserialise as int.
_QUBIT_INDEX_KEYS = ("target", "control", "control0", "control1", "target0", "target1")


def _validate_gates(qubits: int, gates: list[dict[str, Any]]) -> None:
    """Ensure every qubit index referenced by *gates* is within range.

    This is defense in depth: the PHP ``CircuitBuilder`` already validates
    targets before serialising, but the script should not trust its input
    blindly — an out-of-range index should fail with a clear message rather
    than an opaque error from the Braket SDK.

    Args:
        qubits: Total number of qubits in the circuit.
        gates:  Ordered list of gate-definition dicts.

    Raises:
        ValueError: When a gate references a qubit outside ``[0, qubits)``.
    """
    for gate in gates:
        gate_type = gate.get("type", "")

        indices = [gate[key] for key in _QUBIT_INDEX_KEYS if gate.get(key) is not None]

        if gate_type == "measure":
            targets = gate.get("targets")
            if targets is not None:
                # Tolerate a scalar target so a malformed payload still hits the
                # range check below with a clear message, not a raw TypeError.
                indices.extend(targets if isinstance(targets, list) else [targets])

        for index in indices:
            if not isinstance(index, int) or isinstance(index, bool) or not 0 <= index < qubits:
                raise ValueError(
                    f"Gate {gate_type!r} references qubit {index!r}, "
                    f"outside the valid range [0, {qubits})."
                )


def _build_circuit(qubits: int, gates: list[dict[str, Any]]) -> "Circuit":
    """Build a Braket :class:`~braket.circuits.Circuit` from a gate list.

    Supported gate types: ``h``, ``x``, ``y``, ``z``, ``s``, ``t``, ``rx``, ``ry``, ``rz``, ``cnot``, ``cz``, ``swap``, ``ccnot``, ``measure``.
    For ``measure`` gates, a ``null`` / missing ``targets`` field means
    *measure all qubits* (indices 0 through ``qubits-1``).

    Args:
        qubits: Total number of qubits in the circuit.
        gates:  Ordered list of gate-definition dicts.

    Returns:
        A fully-constructed :class:`~braket.circuits.Circuit` instance.

    Raises:
        ValueError: When an unknown gate type or missing required key is
            encountered, or when a gate references an out-of-range qubit.
    """
    _validate_gates(qubits, gates)

    from braket.circuits import Circuit  # noqa: PLC0415

    circuit = Circuit()

    for gate in gates:
        gate_type = gate.get("type", "")

        if gate_type == "h":
            circuit.h(gate["target"])

        elif gate_type == "x":
            circuit.x(gate["target"])

        elif gate_type in ("y", "z", "s", "t"):
            getattr(circuit, gate_type)(gate["target"])

        elif gate_type in ("rx", "ry", "rz"):
            getattr(circuit, gate_type)(gate["target"], gate["angle"])

        elif gate_type == "cnot":
            circuit.cnot(gate["control"], gate["target"])

        elif gate_type == "cz":
            circuit.cz(gate["control"], gate["target"])

        elif gate_type == "swap":
            circuit.swap(gate["target0"], gate["target1"])

        elif gate_type == "ccnot":
            circuit.ccnot(gate["control0"], gate["control1"], gate["target"])

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
