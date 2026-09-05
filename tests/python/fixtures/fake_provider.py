"""Braket-free fake provider used by the load_provider tests.

Implements the full provider contract with stub objects so the resolution
and hook-dispatch logic can be exercised without the Braket SDK.

The stub device is configurable through ``driver_config`` so failure paths
can be driven end-to-end: ``failing_indices`` lists the positions (in call
order for ``run``, in batch order for ``run_batch``) whose task ends without
a result, described by ``failure_state`` and ``failure_reason``.
"""

from typing import Any


class StubResult:
    def __init__(self, counts: dict[str, int]) -> None:
        self.measurement_counts = counts


class StubTask:
    """A task that either carries counts or ends in a terminal failure state."""

    def __init__(
        self,
        counts: dict[str, int] | None = None,
        task_id: str = "stub-task-1",
        state: str = "COMPLETED",
        failure_reason: str | None = None,
    ) -> None:
        self.id = task_id
        self._counts = counts
        self._state = state
        self._failure_reason = failure_reason

    def result(self) -> StubResult | None:
        return None if self._counts is None else StubResult(self._counts)

    def state(self) -> str:
        return self._state

    def metadata(self) -> dict[str, Any]:
        return {} if self._failure_reason is None else {"failureReason": self._failure_reason}


class StubBatch:
    def __init__(self, results: list[StubResult | None], tasks: list[StubTask] | None = None) -> None:
        self._results = results
        self.tasks = tasks if tasks is not None else []

    def results(self) -> list[StubResult | None]:
        return self._results


class StubDevice:
    """Records every call so tests can assert how the scripts drove it."""

    def __init__(
        self,
        failing_indices: tuple[int, ...] = (),
        failure_state: str = "FAILED",
        failure_reason: str | None = None,
    ) -> None:
        self.run_calls: list[dict[str, Any]] = []
        self.run_batch_calls: list[dict[str, Any]] = []
        self.failing_indices = failing_indices
        self.failure_state = failure_state
        self.failure_reason = failure_reason

    def run(self, circuit: Any, shots: int, **kwargs: Any) -> StubTask:
        index = len(self.run_calls)
        self.run_calls.append({"circuit": circuit, "shots": shots, **kwargs})

        return self._task(index, shots)

    def run_batch(self, circuits: list[Any], shots: int | list[int], **kwargs: Any) -> StubBatch:
        self.run_batch_calls.append({"circuits": circuits, "shots": shots, **kwargs})

        tasks = [
            self._task(index, shots[index] if isinstance(shots, list) else shots)
            for index in range(len(circuits))
        ]

        return StubBatch([task.result() for task in tasks], tasks)

    def _task(self, index: int, shots: int) -> StubTask:
        if index in self.failing_indices:
            return StubTask(
                None,
                task_id=f"stub-task-{index + 1}",
                state=self.failure_state,
                failure_reason=self.failure_reason,
            )

        return StubTask({"0": shots}, task_id=f"stub-task-{index + 1}")


def resolve_device(config: dict[str, Any]) -> StubDevice:
    return StubDevice(
        failing_indices=tuple(config.get("failing_indices", ())),
        failure_state=config.get("failure_state", "FAILED"),
        failure_reason=config.get("failure_reason"),
    )


def run_options(config: dict[str, Any]) -> dict[str, Any]:
    return {"tag": config.get("tag", "fake")}


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    return {"status": "COMPLETED", "counts": {"00": 7, "11": 3}}
