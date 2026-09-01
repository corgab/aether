"""Tests for the pure logic in bin/python/common.py.

``validate_gates`` and the ``GATE_PARAMS`` table are braket-free by design
(the Braket imports in common.py are function-local), so those tests always
run. The ``build_circuit`` tests need the Braket SDK and are skipped when it
is not installed.
"""

import importlib.util
import math

import pytest

from common import GATE_PARAMS, build_circuit, validate_gates

HAS_BRAKET = importlib.util.find_spec("braket") is not None

requires_braket = pytest.mark.skipif(
    not HAS_BRAKET,
    reason="amazon-braket-sdk is not installed",
)


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
