"""Subprocess tests for the executing scripts in bin/python.

Runs ``circuit.py``, ``entropy.py`` and ``submit.py`` exactly the way
``PythonBridge`` does — JSON on stdin, JSON on stdout — against the
braket-free stub provider in ``fixtures/fake_provider.py``, and asserts the
bridge contract holds on both channels: stdout carries the single result
document, while every task created along the way is announced on stderr as a
``{"task_arn": ...}`` line the moment it exists.

Building the circuits still goes through the Braket SDK (``build_circuit``
and ``_build_entropy_circuit`` import it), so these tests are skipped when it
is not installed.
"""

import importlib.util
import json
import subprocess
import sys
from pathlib import Path

import pytest

BIN_PYTHON = Path(__file__).resolve().parents[2] / "bin" / "python"
FAKE_PROVIDER_PATH = str(Path(__file__).resolve().parent / "fixtures" / "fake_provider.py")

HAS_BRAKET = importlib.util.find_spec("braket") is not None

requires_braket = pytest.mark.skipif(
    not HAS_BRAKET,
    reason="amazon-braket-sdk is not installed",
)

STUB_TASK_LINE = '{"task_arn": "stub-task-1"}'


def run_script(script: str, payload: dict) -> subprocess.CompletedProcess:
    """Execute *script* as a subprocess with *payload* as JSON on stdin."""
    return subprocess.run(
        [sys.executable, str(BIN_PYTHON / script)],
        input=json.dumps(payload),
        capture_output=True,
        text=True,
        cwd=BIN_PYTHON,
        timeout=60,
        check=True,
    )


def stub_payload(**overrides) -> dict:
    """Build a payload routed to the braket-free stub provider."""
    payload = {
        "shots": 4,
        "driver": "custom",
        "driver_config": {"python_provider": FAKE_PROVIDER_PATH},
    }
    payload.update(overrides)

    return payload


def announced(stderr: str) -> list[str]:
    """Return the task ids announced on *stderr*, in order."""
    return [json.loads(line)["task_arn"] for line in stderr.splitlines() if line.strip()]


@requires_braket
class TestCircuitScript:
    def test_stdout_is_the_single_result_document(self):
        completed = run_script(
            "circuit.py",
            stub_payload(
                qubits=2,
                gates=[
                    {"type": "h", "target": 0},
                    {"type": "cnot", "control": 0, "target": 1},
                    {"type": "measure", "targets": [0, 1]},
                ],
            ),
        )

        assert json.loads(completed.stdout) == {"counts": {"0": 4}}
        assert len(completed.stdout.strip().splitlines()) == 1

    def test_announces_the_task_on_stderr(self):
        completed = run_script(
            "circuit.py",
            stub_payload(qubits=1, gates=[{"type": "h", "target": 0}]),
        )

        assert STUB_TASK_LINE in completed.stderr
        assert announced(completed.stderr) == ["stub-task-1"]


@requires_braket
class TestEntropyScript:
    def test_stdout_is_the_single_result_document(self):
        completed = run_script("entropy.py", stub_payload(qubits=3, shots=2))

        payload = json.loads(completed.stdout)

        # The stub returns one row of bits per shot, one column per qubit.
        assert set(payload) == {"bits"}
        assert len(payload["bits"]) == 6
        assert set(payload["bits"]) <= {"0", "1"}

    def test_announces_the_task_on_stderr(self):
        completed = run_script("entropy.py", stub_payload(qubits=2, shots=1))

        assert STUB_TASK_LINE in completed.stderr
        assert announced(completed.stderr) == ["stub-task-1"]


@requires_braket
class TestSubmitScript:
    def test_stdout_is_the_single_result_document(self):
        completed = run_script(
            "submit.py",
            stub_payload(qubits=1, gates=[{"type": "h", "target": 0}]),
        )

        assert json.loads(completed.stdout) == {"task_arn": "stub-task-1"}

    def test_announces_the_task_on_stderr_as_well(self):
        completed = run_script(
            "submit.py",
            stub_payload(qubits=1, gates=[{"type": "h", "target": 0}]),
        )

        assert announced(completed.stderr) == ["stub-task-1"]
