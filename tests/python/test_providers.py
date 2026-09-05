"""Tests for the pluggable provider layer in bin/python/common.py.

``load_provider``, ``provider_run_options`` and ``default_run_batch`` are
braket-free by design (the Braket imports in the built-in providers are
function-local), so those tests always run. Only the tests that resolve an
actual Braket device need the SDK and are skipped when it is not installed.
"""

import importlib.util
import sys
from pathlib import Path
from types import ModuleType

import pytest

from common import (
    default_run_batch,
    load_provider,
    provider_run_options,
    resolve_run_target,
)
from providers import aws as aws_provider

FIXTURES = Path(__file__).resolve().parent / "fixtures"
FAKE_PROVIDER_PATH = str(FIXTURES / "fake_provider.py")

HAS_BRAKET = importlib.util.find_spec("braket") is not None

requires_braket = pytest.mark.skipif(
    not HAS_BRAKET,
    reason="amazon-braket-sdk is not installed",
)


def _load_fake_provider():
    return load_provider("custom", {"python_provider": FAKE_PROVIDER_PATH})


class TestLoadProvider:
    def test_local_driver_resolves_the_builtin_local_provider(self):
        provider = load_provider("local", {})

        assert provider.__name__ == "providers.local"
        assert callable(provider.resolve_device)

    def test_aws_driver_resolves_the_builtin_aws_provider(self):
        provider = load_provider("aws", {})

        assert provider.__name__ == "providers.aws"
        assert callable(provider.resolve_device)

    def test_python_provider_file_path_wins_over_builtins(self):
        provider = load_provider("local", {"python_provider": FAKE_PROVIDER_PATH})

        assert provider.check_task("t", {}) == {
            "status": "COMPLETED",
            "counts": {"00": 7, "11": 3},
        }

    def test_python_provider_as_file_path_loads_the_module(self):
        provider = _load_fake_provider()

        device = provider.resolve_device({})
        task = device.run("circuit", shots=10)

        assert task.id == "stub-task-1"
        assert task.result().measurement_counts == {"0": 10}

    def test_python_provider_as_module_name_imports_it(self):
        sys.path.insert(0, str(FIXTURES))
        try:
            provider = load_provider("custom", {"python_provider": "fake_provider"})
        finally:
            sys.path.remove(str(FIXTURES))

        assert callable(provider.resolve_device)

    def test_unknown_driver_without_provider_raises(self):
        with pytest.raises(
            ValueError,
            match=r"^Unknown driver 'ionq' and no 'python_provider' configured\.$",
        ):
            load_provider("ionq", {})

    def test_missing_provider_file_raises(self):
        with pytest.raises(ValueError, match=r"^Provider file not found:"):
            load_provider("custom", {"python_provider": "/nonexistent/provider.py"})

    def test_provider_without_resolve_device_raises(self, tmp_path):
        broken = tmp_path / "broken_provider.py"
        broken.write_text("resolve_device = 'not callable'\n")

        with pytest.raises(
            ValueError,
            match=r"does not define a callable resolve_device\(config\)\.$",
        ):
            load_provider("custom", {"python_provider": str(broken)})

    def test_file_provider_is_registered_in_sys_modules_while_executing(self, tmp_path):
        # dataclasses (and pickle, typing.get_type_hints, ...) look a class's
        # module up via sys.modules[cls.__module__]; a module executed without
        # being registered first makes that lookup return None and blow up.
        provider_file = tmp_path / "dataclass_provider.py"
        provider_file.write_text(
            "from __future__ import annotations\n"
            "from dataclasses import dataclass\n"
            "\n"
            "@dataclass\n"
            "class Settings:\n"
            "    arn: str\n"
            "\n"
            "def resolve_device(config):\n"
            "    return Settings(arn=config.get('device_arn', 'none'))\n"
        )

        provider = load_provider("custom", {"python_provider": str(provider_file)})

        assert sys.modules[provider.__name__] is provider
        assert provider.resolve_device({"device_arn": "x"}).arn == "x"

    def test_failing_file_provider_is_not_left_in_sys_modules(self, tmp_path):
        provider_file = tmp_path / "exploding_provider.py"
        provider_file.write_text("raise RuntimeError('boom')\n")

        with pytest.raises(RuntimeError, match="boom"):
            load_provider("custom", {"python_provider": str(provider_file)})

        assert "aether_provider_exploding_provider" not in sys.modules


class TestProviderRunOptions:
    def test_defaults_to_empty_dict_when_hook_is_absent(self):
        provider = load_provider("local", {})

        assert provider_run_options(provider, {}) == {}

    def test_returns_the_hook_result_as_a_dict(self):
        provider = _load_fake_provider()

        assert provider_run_options(provider, {"tag": "abc"}) == {"tag": "abc"}

    def test_aws_run_options_without_bucket_raises(self):
        with pytest.raises(
            ValueError,
            match=r"^Driver 'aws' requires a non-empty 'bucket' in driver_config\.$",
        ):
            provider_run_options(aws_provider, {})

    def test_aws_run_options_returns_the_s3_destination_folder(self):
        options = provider_run_options(aws_provider, {"bucket": "my-bucket"})

        assert options == {"s3_destination_folder": ("my-bucket", "results")}


class TestDefaultRunBatch:
    def test_equal_shots_use_a_single_run_batch_call(self):
        device = _load_fake_provider().resolve_device({})

        results = default_run_batch(device, ["c1", "c2"], [500, 500], {"tag": "x"})

        assert device.run_batch_calls == [
            {"circuits": ["c1", "c2"], "shots": 500, "tag": "x"}
        ]
        assert device.run_calls == []
        assert [r.measurement_counts for r in results] == [{"0": 500}, {"0": 500}]

    def test_mixed_shots_fall_back_to_sequential_runs(self):
        device = _load_fake_provider().resolve_device({})

        results = default_run_batch(device, ["c1", "c2"], [100, 200])

        assert device.run_batch_calls == []
        assert device.run_calls == [
            {"circuit": "c1", "shots": 100},
            {"circuit": "c2", "shots": 200},
        ]
        assert [r.measurement_counts for r in results] == [{"0": 100}, {"0": 200}]


class TestAwsCheckTask:
    """check_task() against a fake braket.aws module injected into sys.modules.

    ``providers.aws`` imports ``AwsQuantumTask`` inside the function, so the
    import resolves through ``sys.modules`` at call time and needs entries for
    both the ``braket`` package and its ``braket.aws`` submodule.
    """

    @pytest.fixture
    def fake_task(self, monkeypatch):
        state = {"value": "COMPLETED", "metadata": {}, "counts": {"00": 5, "11": 5}, "metadata_calls": []}

        class FakeResult:
            measurement_counts = state["counts"]

        class FakeAwsQuantumTask:
            def __init__(self, task_id, aws_session=None):
                self.id = task_id

            def state(self):
                return state["value"]

            def metadata(self, use_cached_value=False):
                state["metadata_calls"].append(use_cached_value)
                return state["metadata"]

            def result(self):
                return FakeResult()

        braket_aws = ModuleType("braket.aws")
        braket_aws.AwsQuantumTask = FakeAwsQuantumTask
        braket_aws.AwsSession = object

        braket_pkg = ModuleType("braket")
        braket_pkg.aws = braket_aws

        monkeypatch.setitem(sys.modules, "braket", braket_pkg)
        monkeypatch.setitem(sys.modules, "braket.aws", braket_aws)
        monkeypatch.setattr(aws_provider, "build_aws_session", lambda config: "session")

        return state

    def test_completed_task_returns_its_counts(self, fake_task):
        output = aws_provider.check_task("arn:task/abc", {})

        assert output == {"status": "COMPLETED", "counts": {"00": 5, "11": 5}}

    def test_failed_task_forwards_the_braket_failure_reason(self, fake_task):
        fake_task["value"] = "FAILED"
        fake_task["metadata"] = {"failureReason": "Device is offline"}

        assert aws_provider.check_task("arn:task/abc", {}) == {
            "status": "FAILED",
            "error": "Device is offline",
        }

    def test_cancelled_task_forwards_the_braket_failure_reason(self, fake_task):
        fake_task["value"] = "CANCELLED"
        fake_task["metadata"] = {"failureReason": "Cancelled by user"}

        assert aws_provider.check_task("arn:task/abc", {})["error"] == "Cancelled by user"

    def test_failed_task_without_a_reason_has_no_error_key(self, fake_task):
        fake_task["value"] = "FAILED"
        fake_task["metadata"] = {"failureReason": ""}

        assert aws_provider.check_task("arn:task/abc", {}) == {"status": "FAILED"}

    def test_in_flight_task_has_neither_counts_nor_error(self, fake_task):
        fake_task["value"] = "RUNNING"

        assert aws_provider.check_task("arn:task/abc", {}) == {"status": "RUNNING"}

    def test_reads_the_failure_reason_from_the_response_state_already_fetched(self, fake_task):
        fake_task["value"] = "FAILED"
        fake_task["metadata"] = {"failureReason": "Device is offline"}

        aws_provider.check_task("arn:task/abc", {})

        assert fake_task["metadata_calls"] == [True]


class TestAwsRunBatch:
    """run_batch() pairs every batch result with its task before failing."""

    def _device(self, **config):
        return load_provider("custom", {"python_provider": FAKE_PROVIDER_PATH, **config}).resolve_device(config)

    def test_returns_one_result_per_circuit(self):
        device = self._device()

        results = aws_provider.run_batch(device, ["c1", "c2"], [100, 200], {"bucket": "b"})

        assert [r.measurement_counts for r in results] == [{"0": 100}, {"0": 200}]
        assert device.run_batch_calls[0]["shots"] == [100, 200]

    def test_names_the_failed_task_and_its_reason(self):
        device = self._device(failing_indices=[1], failure_reason="Device is offline")

        with pytest.raises(
            RuntimeError,
            match=r"^Quantum task stub-task-2 ended in state FAILED: Device is offline$",
        ):
            aws_provider.run_batch(device, ["c1", "c2"], [100, 100], {"bucket": "b"})


class TestResolveRunTarget:
    def test_returns_the_provider_device_and_its_run_options(self):
        device, run_options = resolve_run_target("custom", {"python_provider": FAKE_PROVIDER_PATH, "tag": "abc"})

        assert device.run("circuit", shots=1).id == "stub-task-1"
        assert run_options == {"tag": "abc"}

    def test_run_options_default_to_an_empty_dict(self, tmp_path):
        bare = tmp_path / "bare_provider.py"
        bare.write_text("def resolve_device(config):\n    return 'device'\n")

        device, run_options = resolve_run_target("custom", {"python_provider": str(bare)})

        assert device == "device"
        assert run_options == {}

    @requires_braket
    def test_local_driver_resolves_the_local_simulator(self):
        from braket.devices import LocalSimulator

        device, _ = resolve_run_target("local", {})

        assert isinstance(device, LocalSimulator)
