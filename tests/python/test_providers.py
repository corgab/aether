"""Tests for the pluggable provider layer in bin/python/common.py.

``load_provider``, ``provider_run_options`` and ``default_run_batch`` are
braket-free by design (the Braket imports in the built-in providers are
function-local), so those tests always run. Only the tests that resolve an
actual Braket device need the SDK and are skipped when it is not installed.
"""

import importlib.util
import sys
from pathlib import Path

import pytest

from common import (
    default_run_batch,
    load_provider,
    provider_run_options,
    resolve_device,
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


@requires_braket
class TestResolveDeviceWrapper:
    def test_local_driver_resolves_the_local_simulator(self):
        from braket.devices import LocalSimulator

        assert isinstance(resolve_device("local", {}), LocalSimulator)
