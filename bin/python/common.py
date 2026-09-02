#!/usr/bin/env python3
"""Shared utilities for Aether quantum scripts."""

from typing import Any

# The single Python source of truth for the wire-format gate parameters.
# Each entry maps a gate type to the ordered qubit-index keys and angle keys
# expected in its JSON definition (qubit keys always precede angle keys, in
# wire order). Mirrors the ``GateType`` / ``GateShape`` enums in src/Circuit;
# bin/python/list_gates.py exports this table as JSON and
# tests/Feature/GateParityTest.php asserts parity with the PHP side.
# ``measure`` carries a ``targets`` list instead and is handled by a special
# builder, so it declares no keys here.
GATE_PARAMS: dict[str, dict[str, tuple[str, ...]]] = {
    "h": {"qubits": ("target",), "angles": ()},
    "x": {"qubits": ("target",), "angles": ()},
    "y": {"qubits": ("target",), "angles": ()},
    "z": {"qubits": ("target",), "angles": ()},
    "i": {"qubits": ("target",), "angles": ()},
    "s": {"qubits": ("target",), "angles": ()},
    "si": {"qubits": ("target",), "angles": ()},
    "t": {"qubits": ("target",), "angles": ()},
    "ti": {"qubits": ("target",), "angles": ()},
    "rx": {"qubits": ("target",), "angles": ("angle",)},
    "ry": {"qubits": ("target",), "angles": ("angle",)},
    "rz": {"qubits": ("target",), "angles": ("angle",)},
    "phaseshift": {"qubits": ("target",), "angles": ("angle",)},
    "cnot": {"qubits": ("control", "target"), "angles": ()},
    "cz": {"qubits": ("control", "target"), "angles": ()},
    "cy": {"qubits": ("control", "target"), "angles": ()},
    "crx": {"qubits": ("control", "target"), "angles": ("angle",)},
    "cry": {"qubits": ("control", "target"), "angles": ("angle",)},
    "crz": {"qubits": ("control", "target"), "angles": ("angle",)},
    "cphaseshift": {"qubits": ("control", "target"), "angles": ("angle",)},
    "swap": {"qubits": ("target0", "target1"), "angles": ()},
    "iswap": {"qubits": ("target0", "target1"), "angles": ()},
    "xx": {"qubits": ("target0", "target1"), "angles": ("angle",)},
    "yy": {"qubits": ("target0", "target1"), "angles": ("angle",)},
    "zz": {"qubits": ("target0", "target1"), "angles": ("angle",)},
    "cswap": {"qubits": ("control", "target0", "target1"), "angles": ()},
    "ccnot": {"qubits": ("control0", "control1", "target"), "angles": ()},
    "u": {"qubits": ("target",), "angles": ("theta", "phi", "lambda")},
    "measure": {"qubits": (), "angles": ()},
}


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
        ValueError: When a gate type is not in :data:`GATE_PARAMS`, or when a
            gate references a qubit outside ``[0, qubits)``.
    """
    for gate in gates:
        gate_type = gate.get("type", "")

        spec = GATE_PARAMS.get(gate_type)
        if spec is None:
            raise ValueError(f"Unknown gate type: {gate_type!r}")

        indices = [gate[key] for key in spec["qubits"] if gate.get(key) is not None]

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


# The Braket SDK does not natively support crx, cry, and crz.
# We decompose them into native gates so they run identically
# on the local simulator, SV1, and real QPUs without relying on
# OpenQASM features that may not be supported on real hardware.
def _apply_crx(circuit: "Circuit", gate: dict[str, Any], qubits: int) -> None:
    """Apply a controlled-RX gate as a native-gate decomposition."""
    c, t, theta = gate["control"], gate["target"], gate["angle"]
    circuit.h(t).rz(t, theta / 2).cnot(c, t).rz(t, -theta / 2).cnot(c, t).h(t)


def _apply_cry(circuit: "Circuit", gate: dict[str, Any], qubits: int) -> None:
    """Apply a controlled-RY gate as a native-gate decomposition."""
    c, t, theta = gate["control"], gate["target"], gate["angle"]
    circuit.ry(t, theta / 2).cnot(c, t).ry(t, -theta / 2).cnot(c, t)


def _apply_crz(circuit: "Circuit", gate: dict[str, Any], qubits: int) -> None:
    """Apply a controlled-RZ gate as a native-gate decomposition."""
    c, t, theta = gate["control"], gate["target"], gate["angle"]
    circuit.rz(t, theta / 2).cnot(c, t).rz(t, -theta / 2).cnot(c, t)


def _apply_measure(circuit: "Circuit", gate: dict[str, Any], qubits: int) -> None:
    """Measure the gate's ``targets``, or every qubit when ``targets`` is null."""
    targets = gate.get("targets")
    qubit_indices = targets if targets is not None else list(range(qubits))
    circuit.measure(qubit_indices)


# Gate types that cannot go through the generic ``getattr`` dispatch in
# build_circuit(): the controlled rotations need a decomposition and
# ``measure`` needs the circuit width to expand a null target list.
_SPECIAL_BUILDERS = {
    "crx": _apply_crx,
    "cry": _apply_cry,
    "crz": _apply_crz,
    "measure": _apply_measure,
}


def build_circuit(qubits: int, gates: list[dict[str, Any]]) -> "Circuit":
    """Build a Braket :class:`~braket.circuits.Circuit` from a gate list.

    The supported gate types and their parameter keys are defined by
    :data:`GATE_PARAMS`. Most gates dispatch generically — the Braket
    ``Circuit`` method name equals the wire type — while the entries in
    ``_SPECIAL_BUILDERS`` (``crx``, ``cry``, ``crz``, ``measure``) use
    dedicated builders. For ``measure`` gates, a ``null`` / missing
    ``targets`` field means *measure all qubits* (indices 0 through
    ``qubits-1``).

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

        spec = GATE_PARAMS.get(gate_type)
        if spec is None:
            raise ValueError(f"Unknown gate type: {gate_type!r}")

        special = _SPECIAL_BUILDERS.get(gate_type)
        if special is not None:
            special(circuit, gate, qubits)
            continue

        getattr(circuit, gate_type)(*(gate[key] for key in spec["qubits"] + spec["angles"]))

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
