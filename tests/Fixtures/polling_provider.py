"""Fixture provider for the CustomProviderTest Feature suite.

Resolves a stub device (so executeCircuit works without any real backend)
and implements ``check_task`` so the polling flow can be exercised
end-to-end through check.py without the Braket SDK or AWS credentials.
"""

from typing import Any


class _StubResult:
    def __init__(self, counts: dict[str, int]) -> None:
        self.measurement_counts = counts


class _StubTask:
    id = "custom-task-1"

    def result(self) -> _StubResult:
        return _StubResult({"00": 6, "11": 4})


class _StubDevice:
    def run(self, circuit: Any, shots: int, **kwargs: Any) -> _StubTask:
        return _StubTask()


def resolve_device(config: dict[str, Any]) -> _StubDevice:
    return _StubDevice()


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    return {"status": "COMPLETED", "counts": {"00": 7, "11": 3}}
