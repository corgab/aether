#!/usr/bin/env python3
"""Built-in provider for the Amazon Braket local simulator."""

from typing import Any


def resolve_device(config: dict[str, Any]) -> Any:
    """Return the Braket local simulator; *config* carries no local options."""
    from braket.devices import LocalSimulator  # noqa: PLC0415

    return LocalSimulator()
