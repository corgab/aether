"""Tests for bin/python/list_gates.py.

Runs the script the way PythonBridge does — as a subprocess with JSON on
stdin and JSON on stdout — and asserts the exported table matches
``GATE_PARAMS``. The script deliberately imports nothing from the Braket
SDK, so these tests run on a bare Python installation.
"""

import json
import subprocess
import sys
from pathlib import Path

from common import GATE_PARAMS

BIN_PYTHON = Path(__file__).resolve().parents[2] / "bin" / "python"


def run_list_gates() -> str:
    """Execute list_gates.py as a subprocess, returning its stdout."""
    completed = subprocess.run(
        [sys.executable, str(BIN_PYTHON / "list_gates.py")],
        input="{}",
        capture_output=True,
        text=True,
        cwd=BIN_PYTHON,
        timeout=30,
        check=True,
    )
    return completed.stdout


def test_outputs_valid_json_with_gates_key():
    payload = json.loads(run_list_gates())

    assert isinstance(payload, dict)
    assert "gates" in payload
    assert isinstance(payload["gates"], dict)


def test_exports_29_gates():
    payload = json.loads(run_list_gates())

    assert len(payload["gates"]) == 29


def test_exported_table_matches_gate_params():
    payload = json.loads(run_list_gates())

    expected = {
        gate_type: {"qubits": list(spec["qubits"]), "angles": list(spec["angles"])}
        for gate_type, spec in GATE_PARAMS.items()
    }
    assert payload["gates"] == expected
