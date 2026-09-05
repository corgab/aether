"""Tests for the no-result task handling in bin/python.

Braket returns ``None`` from ``task.result()`` for tasks in a terminal state
that carries no result (``FAILED``, ``CANCELLED``). These tests cover the
helpers that turn that ``None`` into a message carrying the backend's own
failure reason, and the scripts that use them.
"""

import importlib.util
import json
import subprocess
import sys
from pathlib import Path

import pytest

from common import default_run_batch, describe_task_failure, load_provider, require_result

FIXTURES = Path(__file__).resolve().parent / "fixtures"
FAKE_PROVIDER_PATH = str(FIXTURES / "fake_provider.py")
CIRCUIT_SCRIPT = Path(__file__).resolve().parents[2] / "bin" / "python" / "circuit.py"

# circuit.py builds a real braket Circuit before the fake provider runs it.
requires_braket = pytest.mark.skipif(
    importlib.util.find_spec("braket") is None,
    reason="amazon-braket-sdk is not installed",
)


class _Task:
    """A minimal task double; ``state`` / ``metadata`` are opt-in per test."""

    def __init__(self, task_id="task-1", state=None, metadata=None, raising=False):
        self.id = task_id
        self._state = state
        self._metadata = metadata
        self._raising = raising

        if state is not None:
            self.state = lambda: state

        if metadata is not None or raising:
            self.metadata = self._read_metadata

    def _read_metadata(self):
        if self._raising:
            raise RuntimeError("network is down")

        return self._metadata


def _device(**config):
    return load_provider("custom", {"python_provider": FAKE_PROVIDER_PATH, **config}).resolve_device(config)


class TestRequireResult:
    def test_returns_the_result_untouched_when_present(self):
        result = object()

        assert require_result(_Task(), result) is result

    def test_raises_with_the_state_and_the_failure_reason(self):
        task = _Task(task_id="arn:task/abc", state="FAILED", metadata={"failureReason": "Device offline"})

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task arn:task/abc ended in state FAILED: Device offline$",
        ):
            require_result(task, None)

    def test_reads_the_state_from_the_metadata_without_calling_state(self):
        class MetadataOnlyTask:
            id = "task-1"

            def metadata(self):
                return {"status": "FAILED", "failureReason": "Device is offline"}

            def state(self):
                raise AssertionError("state() must not be called when metadata carries the status")

        assert describe_task_failure(MetadataOnlyTask()) == (
            "Quantum task task-1 ended in state FAILED: Device is offline"
        )

    def test_message_omits_the_reason_when_metadata_has_none(self):
        task = _Task(task_id="arn:task/abc", state="CANCELLED", metadata={})

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task arn:task/abc ended in state CANCELLED$",
        ):
            require_result(task, None)

    def test_message_falls_back_when_the_task_exposes_no_state_or_metadata(self):
        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task task-1 ended without a result$",
        ):
            require_result(_Task(), None)

    def test_message_survives_a_metadata_call_that_raises(self):
        task = _Task(state="FAILED", raising=True)

        with pytest.raises(RuntimeError, match=r"^Quantum task task-1 ended in state FAILED$"):
            require_result(task, None)

    def test_unidentifiable_task_is_still_described(self):
        assert describe_task_failure(object()) == "Quantum task <unknown> ended without a result"


class TestDefaultRunBatchFailures:
    def test_raises_naming_the_failing_task_of_a_batch(self):
        device = _device(failing_indices=[1], failure_reason="Device offline")

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task stub-task-2 ended in state FAILED: Device offline$",
        ):
            default_run_batch(device, ["c1", "c2"], [100, 100])

    def test_raises_naming_the_failing_task_of_a_sequential_run(self):
        device = _device(failing_indices=[1], failure_state="CANCELLED")

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task stub-task-2 ended in state CANCELLED$",
        ):
            default_run_batch(device, ["c1", "c2"], [100, 200])

    def test_names_the_batch_index_when_the_batch_exposes_no_tasks(self):
        class TasklessBatch:
            def results(self):
                return [object(), None]

        class TasklessDevice:
            def run_batch(self, circuits, shots, **kwargs):
                return TasklessBatch()

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task at batch index 1 ended without a result$",
        ):
            default_run_batch(TasklessDevice(), ["c1", "c2"], [100, 100])

    def test_reads_the_tasks_only_after_the_results_are_final(self):
        class RetryingBatch:
            """Mimics AwsQuantumTaskBatch: results() retries and swaps in a new task."""

            def __init__(self):
                self._tasks = [_Task("attempt-1", state="FAILED")]

            def results(self):
                self._tasks = [_Task("attempt-2", state="FAILED", metadata={"failureReason": "Still offline"})]
                return [None]

            @property
            def tasks(self):
                return list(self._tasks)

        class RetryingDevice:
            def run_batch(self, circuits, shots, **kwargs):
                return RetryingBatch()

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task attempt-2 ended in state FAILED: Still offline$",
        ):
            default_run_batch(RetryingDevice(), ["c1"], [100])

    def test_successful_batch_still_returns_every_result(self):
        device = _device()

        results = default_run_batch(device, ["c1", "c2"], [100, 100])

        assert [r.measurement_counts for r in results] == [{"0": 100}, {"0": 100}]


@requires_braket
class TestCircuitScript:
    def test_reports_the_failure_reason_on_stderr_and_exits_1(self):
        payload = {
            "qubits": 1,
            "gates": [{"type": "h", "target": 0}, {"type": "measure", "targets": [0]}],
            "shots": 10,
            "driver": "custom",
            "driver_config": {
                "python_provider": FAKE_PROVIDER_PATH,
                "failing_indices": [0],
                "failure_state": "FAILED",
                "failure_reason": "Device is offline",
            },
        }

        process = subprocess.run(
            [sys.executable, str(CIRCUIT_SCRIPT)],
            input=json.dumps(payload),
            capture_output=True,
            text=True,
        )

        assert process.returncode == 1
        assert json.loads(process.stderr)["error"] == (
            "Quantum task stub-task-1 ended in state FAILED: Device is offline"
        )
