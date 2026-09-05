"""Tests for the pure logic in bin/python/common.py.

``validate_gates`` and the ``GATE_PARAMS`` table are braket-free by design
(the Braket imports in common.py are function-local), so those tests always
run. The ``build_circuit`` tests need the Braket SDK and are skipped when it
is not installed.
"""

import importlib.util
import json
import math
from pathlib import Path

import pytest

from common import (
    GATE_PARAMS,
    announce_task,
    announce_tasks,
    build_circuit,
    default_run_batch,
    load_provider,
    validate_gates,
)

HAS_BRAKET = importlib.util.find_spec("braket") is not None

requires_braket = pytest.mark.skipif(
    not HAS_BRAKET,
    reason="amazon-braket-sdk is not installed",
)

FAKE_PROVIDER_PATH = str(Path(__file__).resolve().parent / "fixtures" / "fake_provider.py")


def _fake_device():
    """Resolve the braket-free stub device from the fake provider fixture."""
    return load_provider("custom", {"python_provider": FAKE_PROVIDER_PATH}).resolve_device({})


def _announced(capsys) -> list[str]:
    """Return the task ids announced on stderr so far, in order."""
    lines = capsys.readouterr().err.splitlines()

    return [json.loads(line)["task_arn"] for line in lines if line.strip()]


def _gate_for(gate_type: str, spec: dict) -> dict:
    """Build a syntactically valid gate dict for *gate_type* from its spec."""
    gate = {"type": gate_type}

    for index, key in enumerate(spec["qubits"]):
        gate[key] = index

    for key in spec["angles"]:
        gate[key] = 0.5

    if gate_type == "measure":
        gate["targets"] = None

    return gate


def _all_gates() -> list[dict]:
    """One valid instance of every gate type in GATE_PARAMS."""
    return [_gate_for(gate_type, spec) for gate_type, spec in GATE_PARAMS.items()]


class TestValidateGates:
    def test_valid_gates_pass(self):
        validate_gates(4, _all_gates())

    def test_single_qubit_gate_in_range_passes(self):
        validate_gates(1, [{"type": "h", "target": 0}])

    def test_out_of_range_qubit_raises_with_exact_message(self):
        with pytest.raises(
            ValueError,
            match=r"^Gate 'x' references qubit 2, outside the valid range \[0, 2\)\.$",
        ):
            validate_gates(2, [{"type": "x", "target": 2}])

    def test_missing_qubit_key_raises_a_clear_error(self):
        with pytest.raises(
            ValueError,
            match=r"^Gate 'cnot' is missing required parameter 'target'\.$",
        ):
            validate_gates(2, [{"type": "cnot", "control": 0}])

    def test_missing_angle_key_raises_a_clear_error(self):
        with pytest.raises(
            ValueError,
            match=r"^Gate 'rx' is missing required parameter 'angle'\.$",
        ):
            validate_gates(2, [{"type": "rx", "target": 0}])

    def test_negative_index_raises(self):
        with pytest.raises(ValueError, match=r"references qubit -1"):
            validate_gates(2, [{"type": "h", "target": -1}])

    def test_control_index_is_also_checked(self):
        with pytest.raises(ValueError, match=r"Gate 'cnot' references qubit 5"):
            validate_gates(2, [{"type": "cnot", "control": 5, "target": 1}])

    def test_bool_index_is_rejected(self):
        with pytest.raises(ValueError, match=r"references qubit True"):
            validate_gates(2, [{"type": "h", "target": True}])

    def test_string_index_is_rejected(self):
        with pytest.raises(ValueError, match=r"references qubit '0'"):
            validate_gates(2, [{"type": "h", "target": "0"}])

    def test_measure_with_targets_list_passes(self):
        validate_gates(3, [{"type": "measure", "targets": [0, 2]}])

    def test_measure_with_out_of_range_target_in_list_raises(self):
        with pytest.raises(ValueError, match=r"Gate 'measure' references qubit 3"):
            validate_gates(3, [{"type": "measure", "targets": [0, 3]}])

    def test_measure_with_scalar_target_is_range_checked(self):
        validate_gates(3, [{"type": "measure", "targets": 1}])

        with pytest.raises(ValueError, match=r"Gate 'measure' references qubit 7"):
            validate_gates(3, [{"type": "measure", "targets": 7}])

    def test_measure_with_null_targets_passes(self):
        validate_gates(3, [{"type": "measure", "targets": None}])

    def test_measure_with_absent_targets_passes(self):
        validate_gates(3, [{"type": "measure"}])

    def test_unknown_gate_type_raises(self):
        with pytest.raises(ValueError, match=r"^Unknown gate type: 'teleport'$"):
            validate_gates(2, [{"type": "teleport", "target": 0}])

    def test_missing_type_key_raises_unknown_gate(self):
        with pytest.raises(ValueError, match=r"^Unknown gate type: ''$"):
            validate_gates(2, [{"target": 0}])

    def test_empty_gate_list_passes(self):
        validate_gates(1, [])


class TestGateParamsTable:
    def test_table_has_29_entries(self):
        assert len(GATE_PARAMS) == 29

    def test_every_entry_declares_qubits_and_angles_tuples(self):
        for gate_type, spec in GATE_PARAMS.items():
            assert set(spec.keys()) == {"qubits", "angles"}, gate_type
            assert isinstance(spec["qubits"], tuple), gate_type
            assert isinstance(spec["angles"], tuple), gate_type

    def test_u_gate_declares_theta_phi_lambda(self):
        assert GATE_PARAMS["u"]["qubits"] == ("target",)
        assert GATE_PARAMS["u"]["angles"] == ("theta", "phi", "lambda")

    def test_measure_declares_no_keys(self):
        assert GATE_PARAMS["measure"] == {"qubits": (), "angles": ()}

    def test_controlled_gates_declare_control_before_target(self):
        for gate_type in ("cnot", "cz", "cy", "crx", "cry", "crz", "cphaseshift"):
            assert GATE_PARAMS[gate_type]["qubits"] == ("control", "target"), gate_type


@requires_braket
class TestBuildCircuit:
    @staticmethod
    def _instructions(circuit):
        return [
            (
                instruction.operator.name,
                [int(qubit) for qubit in instruction.target],
                getattr(instruction.operator, "angle", None),
            )
            for instruction in circuit.instructions
        ]

    def test_crz_decomposes_into_rz_cnot_rz_cnot(self):
        theta = math.pi / 2
        circuit = build_circuit(2, [{"type": "crz", "control": 0, "target": 1, "angle": theta}])

        assert self._instructions(circuit) == [
            ("Rz", [1], theta / 2),
            ("CNot", [0, 1], None),
            ("Rz", [1], -theta / 2),
            ("CNot", [0, 1], None),
        ]

    def test_cry_decomposes_into_ry_cnot_ry_cnot(self):
        theta = 1.0
        circuit = build_circuit(2, [{"type": "cry", "control": 0, "target": 1, "angle": theta}])

        assert self._instructions(circuit) == [
            ("Ry", [1], theta / 2),
            ("CNot", [0, 1], None),
            ("Ry", [1], -theta / 2),
            ("CNot", [0, 1], None),
        ]

    def test_crx_wraps_the_crz_decomposition_in_hadamards(self):
        theta = 1.0
        circuit = build_circuit(2, [{"type": "crx", "control": 0, "target": 1, "angle": theta}])

        assert self._instructions(circuit) == [
            ("H", [1], None),
            ("Rz", [1], theta / 2),
            ("CNot", [0, 1], None),
            ("Rz", [1], -theta / 2),
            ("CNot", [0, 1], None),
            ("H", [1], None),
        ]

    def test_generic_dispatch_builds_every_gate_type(self):
        circuit = build_circuit(4, _all_gates())

        # 25 generic gates + crx (6) + cry (4) + crz (4) + measure-all on 4 qubits (4).
        assert len(circuit.instructions) == 43

    def test_measure_with_null_targets_measures_every_qubit(self):
        circuit = build_circuit(3, [{"type": "h", "target": 0}, {"type": "measure", "targets": None}])

        measured = [
            int(qubit)
            for instruction in circuit.instructions
            if instruction.operator.name == "Measure"
            for qubit in instruction.target
        ]
        assert measured == [0, 1, 2]

    def test_measure_with_explicit_targets_measures_only_those(self):
        circuit = build_circuit(3, [{"type": "measure", "targets": [0, 2]}])

        measured = [
            int(qubit)
            for instruction in circuit.instructions
            if instruction.operator.name == "Measure"
            for qubit in instruction.target
        ]
        assert measured == [0, 2]

    def test_out_of_range_qubit_raises_before_building(self):
        with pytest.raises(ValueError, match=r"outside the valid range"):
            build_circuit(2, [{"type": "h", "target": 9}])


class TestAnnounceTask:
    def test_writes_exactly_one_json_line_to_stderr(self, capsys):
        class Task:
            id = "arn:aws:braket:us-east-1:123456789012:quantum-task/abc"

        announce_task(Task())

        captured = capsys.readouterr()
        assert captured.out == ""
        assert captured.err == (
            '{"task_arn": "arn:aws:braket:us-east-1:123456789012:quantum-task/abc"}\n'
        )
        assert json.loads(captured.err) == {"task_arn": Task.id}

    def test_skips_a_task_without_an_id(self, capsys):
        announce_task(object())

        assert capsys.readouterr().err == ""

    def test_skips_a_non_string_id(self, capsys):
        class Task:
            id = 42

        announce_task(Task())

        assert capsys.readouterr().err == ""

    def test_skips_an_empty_id(self, capsys):
        class Task:
            id = ""

        announce_task(Task())

        assert capsys.readouterr().err == ""

    def test_announce_tasks_writes_one_line_per_task(self, capsys):
        class Task:
            def __init__(self, task_id):
                self.id = task_id

        announce_tasks([Task("one"), Task("two"), object(), Task("three")])

        assert _announced(capsys) == ["one", "two", "three"]

    def test_announce_tasks_on_an_empty_iterable_is_silent(self, capsys):
        announce_tasks([])

        assert capsys.readouterr().err == ""


class TestDefaultRunBatchAnnouncesTasks:
    def test_uniform_shots_announce_every_batch_task(self, capsys):
        device = _fake_device()

        default_run_batch(device, ["c1", "c2"], [500, 500], {"tag": "x"})

        assert _announced(capsys) == ["stub-task-1", "stub-task-2"]

    def test_mixed_shots_announce_each_task_before_its_result(self, capsys):
        device = _fake_device()

        default_run_batch(device, ["c1", "c2"], [100, 200])

        assert _announced(capsys) == ["stub-task-1", "stub-task-2"]

    def test_a_batch_without_tasks_is_tolerated(self, capsys):
        class TasklessBatch:
            def results(self):
                return []

        class TasklessDevice:
            def run_batch(self, circuits, shots, **kwargs):
                return TasklessBatch()

        assert default_run_batch(TasklessDevice(), ["c1"], [10]) == []
        assert capsys.readouterr().err == ""
