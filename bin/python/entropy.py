#!/usr/bin/env python3
"""Quantum entropy generator for the Aether Laravel package.

Reads a request as JSON from stdin, builds a Hadamard circuit with *N* qubits,
runs it with configurable shot count, and writes the resulting random bitstring
to stdout.

The randomness is quantum in origin: each qubit is placed in an equal
superposition by a Hadamard gate and then measured, producing truly random bits
per qubit (on real hardware) or pseudorandom bits (on the local simulator).
Multi-shot support allows generating longer bitstrings efficiently by running
the circuit multiple times and concatenating all measurement results.

Input schema (JSON on stdin)::

    {
        "qubits": 16,
        "shots": 2,
        "driver": "local",
        "driver_config": {}
    }

Output (JSON on stdout)::

    {"bits": "10110011..."}

On error the script writes ``{"error": "<message>"}`` to stderr and exits
with code 1.

Device resolution mirrors :mod:`circuit` exactly; see that module for full
documentation of the ``driver`` / ``driver_config`` semantics.
"""

import json
import sys
from typing import Any

from common import resolve_run_target


def _build_entropy_circuit(n_qubits: int) -> "Circuit":
    """Build a circuit that applies a Hadamard gate and measures every qubit.

    Each qubit is initialised in state |0⟩, placed in an equal superposition
    by a Hadamard gate, and then measured. A single shot of this circuit
    produces one uniformly-distributed random bit per qubit.

    Args:
        n_qubits: Number of qubits (and therefore random bits per shot) to generate.

    Returns:
        A :class:`~braket.circuits.Circuit` with ``n_qubits`` qubits.
    """
    from braket.circuits import Circuit  # noqa: PLC0415

    circuit = Circuit()
    for qubit in range(n_qubits):
        circuit.h(qubit)
    circuit.measure(list(range(n_qubits)))
    return circuit


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Execute an entropy circuit and return the measured bitstring.

    The circuit uses the configured number of qubits with the computed number
    of shots. All shot results are concatenated into a single bitstring.

    Args:
        payload: Deserialised JSON input dict with keys: qubits, shots,
            driver, driver_config.

    Returns:
        A dict with a single ``"bits"`` key whose value is the concatenated
        bitstring from all shots.
    """
    n_qubits: int = payload["qubits"]
    shots: int = payload["shots"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    circuit = _build_entropy_circuit(n_qubits)
    device, run_options = resolve_run_target(driver, driver_config)

    task = device.run(circuit, shots=shots, **run_options)
    result = task.result()

    measurements = result.measurements
    if measurements is None or len(measurements) == 0:
        raise RuntimeError("No measurement data returned from device.")

    bitstring = "".join(
        "".join(str(bit) for bit in shot)
        for shot in measurements
    )

    return {"bits": bitstring}


def main() -> None:
    """Entry point: read JSON from stdin, generate entropy, write JSON to stdout."""
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
