#!/usr/bin/env python3
"""Quantum entropy generator for the Aether Laravel package.

Reads a request as JSON from stdin, builds a single-shot Hadamard circuit
with *N* qubits, runs it, and writes the resulting random bitstring to stdout.

The randomness is quantum in origin: each qubit is placed in an equal
superposition by a Hadamard gate and then measured exactly once, producing
a truly random bit per qubit (on real hardware) or a pseudorandom bit (on the
local simulator).

Input schema (JSON on stdin)::

    {
        "bits": 32,
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


def _resolve_device(driver: str, driver_config: dict[str, Any]) -> Any:
    """Resolve the Braket device from the driver name and its configuration.

    For ``"local"`` the Amazon Braket local simulator is returned directly.
    For ``"aws"`` a real :class:`~braket.aws.AwsDevice` is constructed using
    a :class:`boto3.Session` seeded from ``driver_config``.

    AWS-specific imports are intentionally deferred to this branch so that
    the script can be imported or tested without the full AWS SDK installed.

    Args:
        driver:        Either ``"local"`` or ``"aws"``.
        driver_config: Driver-specific options (region, device_arn, …).

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


def _build_entropy_circuit(n_bits: int) -> "Circuit":
    """Build a circuit that applies a Hadamard gate and measures every qubit.

    Each qubit is initialised in state |0⟩, placed in an equal superposition
    by a Hadamard gate, and then measured. A single shot of this circuit
    produces one uniformly-distributed random bit per qubit.

    Args:
        n_bits: Number of qubits (and therefore random bits) to generate.

    Returns:
        A :class:`~braket.circuits.Circuit` with ``n_bits`` qubits.
    """
    from braket.circuits import Circuit  # noqa: PLC0415

    circuit = Circuit()
    for qubit in range(n_bits):
        circuit.h(qubit)
    circuit.measure(list(range(n_bits)))
    return circuit


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    """Execute an entropy circuit and return the measured bitstring.

    A single shot is used so that exactly one outcome is observed, giving a
    concrete bitstring rather than a probability distribution.

    Args:
        payload: Deserialised JSON input dict.

    Returns:
        A dict with a single ``"bits"`` key whose value is the bitstring.

    Raises:
        RuntimeError: When the result contains no measurements (should never
            happen with shots=1 on a well-formed circuit).
    """
    n_bits: int = payload["bits"]
    driver: str = payload.get("driver", "local")
    driver_config: dict[str, Any] = payload.get("driver_config", {})

    circuit = _build_entropy_circuit(n_bits)
    device = _resolve_device(driver, driver_config)

    run_kwargs: dict[str, Any] = {"shots": 1}

    if driver == "aws":
        bucket = driver_config.get("bucket")
        if bucket:
            run_kwargs["s3_destination_folder"] = (bucket, "results")

    task = device.run(circuit, **run_kwargs)
    result = task.result()

    # measurements is a 2-D list: [[bit0, bit1, ...]] for shots=1
    measurements = result.measurements
    if not measurements or not measurements[0]:
        raise RuntimeError("No measurement data returned from device.")

    bitstring = "".join(str(bit) for bit in measurements[0])
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
