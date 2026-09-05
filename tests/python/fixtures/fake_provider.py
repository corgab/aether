"""Braket-free fake provider used by the load_provider tests.

Implements the full provider contract with stub objects so the resolution
and hook-dispatch logic can be exercised without the Braket SDK.
"""

from typing import Any


def _qubit_count(circuit: Any) -> int:
    """Infer the circuit width, falling back to a single qubit."""
    qubits = getattr(circuit, "qubit_count", 1)

    return qubits if isinstance(qubits, int) and qubits > 0 else 1


class StubResult:
    def __init__(self, counts: dict[str, int], shots: int = 0, qubits: int = 1) -> None:
        self.measurement_counts = counts
        # entropy.py reads result.measurements: one row of 0/1 bits per shot,
        # one column per qubit. Deterministic here, but the right shape.
        self.measurements = [
            [(shot + qubit) % 2 for qubit in range(qubits)] for shot in range(shots)
        ]


class StubTask:
    def __init__(
        self,
        counts: dict[str, int],
        task_id: str = "stub-task-1",
        shots: int = 0,
        qubits: int = 1,
    ) -> None:
        self.id = task_id
        self._counts = counts
        self._shots = shots
        self._qubits = qubits

    def result(self) -> StubResult:
        return StubResult(self._counts, self._shots, self._qubits)


class StubBatch:
    """A submitted batch: ``tasks`` exists before ``results()`` is awaited."""

    def __init__(self, tasks: list[StubTask]) -> None:
        self.tasks = tasks

    def results(self) -> list[StubResult]:
        return [task.result() for task in self.tasks]


class StubDevice:
    """Records every call so tests can assert how the scripts drove it."""

    def __init__(self) -> None:
        self.run_calls: list[dict[str, Any]] = []
        self.run_batch_calls: list[dict[str, Any]] = []

    def run(self, circuit: Any, shots: int, **kwargs: Any) -> StubTask:
        self.run_calls.append({"circuit": circuit, "shots": shots, **kwargs})
        # Distinct per run, like a real backend, so ordering assertions on
        # announced ids are meaningful; the first run keeps "stub-task-1".
        task_id = f"stub-task-{len(self.run_calls)}"
        return StubTask({"0": shots}, task_id, shots=shots, qubits=_qubit_count(circuit))

    def run_batch(self, circuits: list[Any], shots: int, **kwargs: Any) -> StubBatch:
        self.run_batch_calls.append({"circuits": circuits, "shots": shots, **kwargs})
        return StubBatch([
            StubTask({"0": shots}, f"stub-task-{index}", shots, _qubit_count(circuit))
            for index, circuit in enumerate(circuits, start=1)
        ])


def resolve_device(config: dict[str, Any]) -> StubDevice:
    return StubDevice()


def run_options(config: dict[str, Any]) -> dict[str, Any]:
    return {"tag": config.get("tag", "fake")}


def check_task(task_id: str, config: dict[str, Any]) -> dict[str, Any]:
    return {"status": "COMPLETED", "counts": {"00": 7, "11": 3}}
