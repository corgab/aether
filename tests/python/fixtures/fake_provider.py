"""Braket-free fake provider used by the load_provider tests.

Implements the full provider contract with stub objects so the resolution
and hook-dispatch logic can be exercised without the Braket SDK.
"""

from typing import Any


class StubResult:
    def __init__(self, counts: dict[str, int]) -> None:
        self.measurement_counts = counts


class StubTask:
    def __init__(self, counts: dict[str, int]) -> None:
        self.id = "stub-task-1"
        self._counts = counts

    def result(self) -> StubResult:
        return StubResult(self._counts)


class StubBatch:
    def __init__(self, results: list[StubResult]) -> None:
        self._results = results

    def results(self) -> list[StubResult]:
        return self._results


class StubDevice:
    """Records every call so tests can assert how the scripts drove it."""

    def __init__(self) -> None:
        self.run_calls: list[dict[str, Any]] = []
        self.run_batch_calls: list[dict[str, Any]] = []

    def run(self, circuit: Any, shots: int, **kwargs: Any) -> StubTask:
        self.run_calls.append({"circuit": circuit, "shots": shots, **kwargs})
        return StubTask({"0": shots})

    def run_batch(self, circuits: list[Any], shots: int, **kwargs: Any) -> StubBatch:
        self.run_batch_calls.append({"circuits": circuits, "shots": shots, **kwargs})
        return StubBatch([StubResult({"0": shots}) for _ in circuits])


def resolve_device(config: dict[str, Any]) -> StubDevice:
    return StubDevice()


def run_options(config: dict[str, Any]) -> dict[str, Any]:
    return {"tag": config.get("tag", "fake")}


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    return {"status": "COMPLETED", "counts": {"00": 7, "11": 3}}
