#!/usr/bin/env python3
"""Shared utilities for Aether quantum scripts."""

from typing import Any

# Gate parameter keys that hold a qubit index (``measure`` is handled
# separately because its targets live in a list). Keep in sync with the gate
# branches in build_circuit(); an allowlist is used deliberately rather than
# "every int parameter" because params such as ``angle`` may deserialise as int.
_QUBIT_INDEX_KEYS = ("target", "control", "control0", "control1", "target0", "target1")


def validate_gates(qubits: int, gates: list[dict[str, Any]]) -> None:
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


def build_circuit(qubits: int, gates: list[dict[str, Any]]) -> "Circuit":
    """Build a Braket :class:`~braket.circuits.Circuit` from a gate list.

    Supported gate types: ``h``, ``x``, ``y``, ``z``, ``i``, ``s``, ``si``, ``t``, ``ti``, ``rx``, ``ry``, ``rz``, ``cnot``, ``cz``, ``cy``, ``swap``, ``ccnot``, ``crx``, ``cry``, ``crz``, ``cphaseshift``, ``phaseshift``, ``u``, ``cswap``, ``iswap``, ``xx``, ``yy``, ``zz``, ``measure``.
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
    validate_gates(qubits, gates)

    from braket.circuits import Circuit  # noqa: PLC0415

    circuit = Circuit()

    for gate in gates:
        gate_type = gate.get("type", "")

        if gate_type == "h":
            circuit.h(gate["target"])

        elif gate_type == "x":
            circuit.x(gate["target"])

        elif gate_type in ("y", "z", "s", "t", "i", "si", "ti"):
            getattr(circuit, gate_type)(gate["target"])

        elif gate_type in ("rx", "ry", "rz", "phaseshift"):
            getattr(circuit, gate_type)(gate["target"], gate["angle"])

        elif gate_type == "cphaseshift":
            getattr(circuit, gate_type)(gate["control"], gate["target"], gate["angle"])

        # The Braket SDK does not natively support crx, cry, and crz.
        # We decompose them into native gates so they run identically
        # on the local simulator, SV1, and real QPUs without relying on
        # OpenQASM features that may not be supported on real hardware.
        elif gate_type == "crz":
            c, t, theta = gate["control"], gate["target"], gate["angle"]
            circuit.rz(t, theta / 2).cnot(c, t).rz(t, -theta / 2).cnot(c, t)

        elif gate_type == "cry":
            c, t, theta = gate["control"], gate["target"], gate["angle"]
            circuit.ry(t, theta / 2).cnot(c, t).ry(t, -theta / 2).cnot(c, t)

        elif gate_type == "crx":
            c, t, theta = gate["control"], gate["target"], gate["angle"]
            circuit.h(t).rz(t, theta / 2).cnot(c, t).rz(t, -theta / 2).cnot(c, t).h(t)

        elif gate_type == "u":
            circuit.u(gate["target"], gate["theta"], gate["phi"], gate["lambda"])

        elif gate_type == "cnot":
            circuit.cnot(gate["control"], gate["target"])

        elif gate_type in ("cz", "cy"):
            getattr(circuit, gate_type)(gate["control"], gate["target"])

        elif gate_type in ("swap", "iswap"):
            getattr(circuit, gate_type)(gate["target0"], gate["target1"])

        elif gate_type == "cswap":
            circuit.cswap(gate["control"], gate["target0"], gate["target1"])

        elif gate_type in ("xx", "yy", "zz"):
            getattr(circuit, gate_type)(gate["target0"], gate["target1"], gate["angle"])

        elif gate_type == "ccnot":
            circuit.ccnot(gate["control0"], gate["control1"], gate["target"])

        elif gate_type == "measure":
            targets = gate.get("targets")
            qubit_indices = targets if targets is not None else list(range(qubits))
            circuit.measure(qubit_indices)

        else:
            raise ValueError(f"Unknown gate type: {gate_type!r}")

    return circuit


def build_aws_session(driver_config: dict[str, Any]) -> "AwsSession":
    """Construct an ``AwsSession`` seeded from *driver_config*.

    Factored out so both device resolution (submit/run) and task polling
    (check.py) build the session identically, from a single implementation.

    Args:
        driver_config: Driver-specific options (currently just ``region``).

    Returns:
        A configured :class:`~braket.aws.AwsSession` instance.
    """
    import boto3  # noqa: PLC0415
    from braket.aws import AwsSession  # noqa: PLC0415

    region = driver_config.get("region", "us-east-1")
    boto_session = boto3.Session(region_name=region)
    return AwsSession(boto_session=boto_session)


def resolve_device(driver: str, driver_config: dict[str, Any]) -> Any:
    """Resolve the Braket device from the driver name and its configuration.

    For ``"local"`` the Amazon Braket local simulator is returned directly.
    For ``"aws"`` a real :class:`~braket.aws.AwsDevice` is constructed using
    a :class:`boto3.Session` seeded from ``driver_config``.

    Args:
        driver:        Either ``"local"`` or ``"aws"``.
        driver_config: Driver-specific options (region, device_arn, ...).

    Returns:
        A Braket device object (either a local simulator or an AwsDevice).

    Raises:
        ValueError: When ``driver`` is not a recognised value.
    """
    if driver == "local":
        from braket.devices import LocalSimulator  # noqa: PLC0415

        return LocalSimulator()

    if driver == "aws":
        from braket.aws import AwsDevice  # noqa: PLC0415

        aws_session = build_aws_session(driver_config)

        device_arn = driver_config.get(
            "device_arn",
            "arn:aws:braket:::device/quantum-simulator/amazon/sv1",
        )
        return AwsDevice(device_arn, aws_session=aws_session)

    raise ValueError(f"Unknown driver: {driver!r}")
