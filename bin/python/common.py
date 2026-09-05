#!/usr/bin/env python3
"""Shared utilities for Aether quantum scripts."""

import importlib
import importlib.util
import sys
from pathlib import Path
from types import ModuleType
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
        ValueError: When a gate type is not in :data:`GATE_PARAMS`, when a
            gate lacks one of the parameter keys its type requires, or when a
            gate references a qubit outside ``[0, qubits)``.
    """
    for gate in gates:
        gate_type = gate.get("type", "")

        spec = GATE_PARAMS.get(gate_type)
        if spec is None:
            raise ValueError(f"Unknown gate type: {gate_type!r}")

        for key in spec["qubits"] + spec["angles"]:
            if key not in gate:
                raise ValueError(f"Gate {gate_type!r} is missing required parameter {key!r}.")

        indices = [gate[key] for key in spec["qubits"]]

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
        # validate_gates() above already rejected unknown types and
        # out-of-range indices, so the lookup cannot miss here.
        spec = GATE_PARAMS[gate_type]

        special = _SPECIAL_BUILDERS.get(gate_type)
        if special is not None:
            special(circuit, gate, qubits)
            continue

        getattr(circuit, gate_type)(*(gate[key] for key in spec["qubits"] + spec["angles"]))

    return circuit


# Providers shipped with the package, keyed by the wire-format driver name.
# A "python_provider" key in driver_config always takes precedence, so a
# custom provider can also shadow a built-in driver name.
_BUILTIN_PROVIDERS: dict[str, str] = {
    "local": "providers.local",
    "aws": "providers.aws",
}


def load_provider(driver: str, driver_config: dict[str, Any]) -> ModuleType:
    """Load the provider module for *driver*.

    A provider is a plain Python module exposing module-level hooks. Only the
    first is required::

        resolve_device(config) -> device          # required
        run_options(config) -> dict               # extra device.run() kwargs
        run_batch(device, circuits, shots_list, config) -> list[Result]
        check_task(task_id, config) -> {"status": ..., "counts": ...}

    Resolution order: the ``python_provider`` key in *driver_config* (either a
    filesystem path to a ``.py`` file or an importable module name) wins over
    the built-in providers registered for the driver name.

    Args:
        driver:        The wire-format driver name (e.g. ``"local"``).
        driver_config: Driver-specific options from the PHP payload.

    Returns:
        The loaded provider module.

    Raises:
        ValueError: When no provider is registered or configured for
            *driver*, when a configured provider file does not exist, or when
            the loaded module does not define a callable ``resolve_device``.
    """
    reference = driver_config.get("python_provider") or _BUILTIN_PROVIDERS.get(driver)

    if not reference:
        raise ValueError(f"Unknown driver {driver!r} and no 'python_provider' configured.")

    reference = str(reference)

    if reference.endswith(".py"):
        path = Path(reference)
        if not path.is_file():
            raise ValueError(f"Provider file not found: {reference!r}")

        spec = importlib.util.spec_from_file_location(f"aether_provider_{path.stem}", path)
        if spec is None or spec.loader is None:
            raise ValueError(f"Could not load provider file: {reference!r}")

        provider = importlib.util.module_from_spec(spec)
        # Register before executing, as importlib itself does: dataclasses,
        # pickle, typing.get_type_hints and friends look the module up via
        # sys.modules[cls.__module__] and fail on an unregistered module.
        sys.modules[spec.name] = provider
        try:
            spec.loader.exec_module(provider)
        except BaseException:
            sys.modules.pop(spec.name, None)
            raise
    else:
        provider = importlib.import_module(reference)

    if not callable(getattr(provider, "resolve_device", None)):
        raise ValueError(
            f"Provider {reference!r} does not define a callable resolve_device(config)."
        )

    return provider


def resolve_run_target(driver: str, driver_config: dict[str, Any]) -> tuple[Any, dict[str, Any]]:
    """Resolve the device for *driver* and the extra ``device.run()`` kwargs.

    The shared prologue of every single-circuit script (``circuit.py``,
    ``submit.py``, ``entropy.py``): load the provider, resolve its device and
    collect the provider's ``run_options``. ``batch.py`` keeps the provider
    itself because it also needs the optional ``run_batch`` hook.

    Args:
        driver:        The wire-format driver name (e.g. ``"local"``).
        driver_config: Driver-specific options (region, device_arn, ...).

    Returns:
        A ``(device, run_options)`` pair; ``run_options`` is ``{}`` when the
        provider declares none.

    Raises:
        ValueError: When no provider resolves for *driver* (see
            :func:`load_provider`) or the provider rejects its configuration.
    """
    provider = load_provider(driver, driver_config)

    return provider.resolve_device(driver_config), provider_run_options(provider, driver_config)


def provider_run_options(provider: ModuleType, driver_config: dict[str, Any]) -> dict[str, Any]:
    """Return the extra ``device.run()`` kwargs declared by *provider*.

    Args:
        provider:      A provider module returned by :func:`load_provider`.
        driver_config: Driver-specific options passed to the hook.

    Returns:
        The dict returned by the provider's optional ``run_options`` hook, or
        an empty dict when the hook is absent or returns a falsy value.
    """
    hook = getattr(provider, "run_options", None)

    if not callable(hook):
        return {}

    options = hook(driver_config)
    return dict(options) if options else {}


def _call_task_hook(task: Any, name: str) -> Any:
    """Call the no-argument *name* hook on *task*, tolerating its absence.

    Task objects come from whatever provider is configured, so neither hook
    is guaranteed to exist — and ``metadata()`` performs a network call on a
    real ``AwsQuantumTask``, which can raise. Both cases mean "no information
    available" rather than a new failure to report.

    Args:
        task: The provider's task object.
        name: The hook name (``"state"`` or ``"metadata"``).

    Returns:
        Whatever the hook returned, or ``None`` when it is missing or raised.
    """
    hook = getattr(task, name, None)

    if not callable(hook):
        return None

    try:
        return hook()
    except Exception:  # noqa: BLE001
        return None


def describe_task_failure(task: Any) -> str:
    """Describe why *task* produced no result, as a human-readable message.

    Braket returns ``None`` from ``AwsQuantumTask.result()`` for every task in
    a terminal state that carries no result (``FAILED``, ``CANCELLED``). The
    reason why lives in the raw ``GetQuantumTask`` response, exposed as
    ``task.metadata()["failureReason"]``.

    Args:
        task: The provider's task object.

    Returns:
        ``Quantum task <id> ended in state <STATE>: <failureReason>``, with
        the reason clause omitted when the backend reported none and the
        state clause replaced by ``ended without a result`` when the task
        exposes no state.
    """
    task_id = getattr(task, "id", None) or "<unknown>"
    state = _call_task_hook(task, "state")

    message = (
        f"Quantum task {task_id} ended in state {state}"
        if state
        else f"Quantum task {task_id} ended without a result"
    )

    metadata = _call_task_hook(task, "metadata")
    reason = metadata.get("failureReason") if isinstance(metadata, dict) else None

    return f"{message}: {reason}" if reason else message


def require_result(task: Any, result: Any) -> Any:
    """Return *result*, or raise a descriptive error when *task* produced none.

    Args:
        task:   The provider's task object, used to describe the failure.
        result: Whatever ``task.result()`` returned.

    Returns:
        The result object, unchanged, when it is not ``None``.

    Raises:
        RuntimeError: When *result* is ``None``, with the message built by
            :func:`describe_task_failure`.
    """
    if result is None:
        raise RuntimeError(describe_task_failure(task))

    return result


def _require_batch_result(index: int, task: Any, result: Any) -> Any:
    """Like :func:`require_result`, for a batch entry whose task may be unknown.

    Generic batch objects are not required to expose their tasks, so a
    missing result there can only be reported by position.

    Args:
        index:  The circuit's position in the batch, in input order.
        task:   The task behind that position, or ``None`` when the batch
                object does not expose one.
        result: Whatever the batch returned for that position.

    Returns:
        The result object, unchanged, when it is not ``None``.

    Raises:
        RuntimeError: When *result* is ``None``.
    """
    if task is not None:
        return require_result(task, result)

    if result is None:
        raise RuntimeError(f"Quantum task at batch index {index} ended without a result")

    return result


def default_run_batch(
    device: Any,
    circuits: list[Any],
    shots_list: list[int],
    run_options: dict[str, Any] | None = None,
) -> list[Any]:
    """Run *circuits* as a batch for providers without a ``run_batch`` hook.

    When every circuit uses the same shot count, the whole list goes through
    ``device.run_batch`` in one call. Braket's generic ``run_batch`` does not
    accept per-task shot counts, so mixed shots fall back to running the
    circuits sequentially inside this same process.

    Args:
        device:      The device resolved by the provider.
        circuits:    The built circuits, in input order.
        shots_list:  One shot count per circuit.
        run_options: Extra ``device.run()`` kwargs from the provider.

    Returns:
        One result object per circuit, in input order.

    Raises:
        RuntimeError: When any task in the batch finished without a result.
    """
    options = dict(run_options or {})

    if len(set(shots_list)) == 1:
        # fail_unsuccessful is an AwsQuantumTaskBatch extra; the generic
        # batch objects this fallback exists for do not accept it, so the
        # None results it would have raised on are checked here instead.
        batch = device.run_batch(circuits, shots=shots_list[0], **options)
        tasks = getattr(batch, "tasks", None)
        tasks = tasks if isinstance(tasks, (list, tuple)) else ()

        return [
            _require_batch_result(index, tasks[index] if index < len(tasks) else None, result)
            for index, result in enumerate(batch.results())
        ]

    results: list[Any] = []
    for circuit, shots in zip(circuits, shots_list):
        task = device.run(circuit, shots=shots, **options)
        results.append(require_result(task, task.result()))

    return results
