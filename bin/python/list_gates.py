#!/usr/bin/env python3
"""Gate-table exporter for the Aether Laravel package.

Reads (and ignores) stdin, then writes the ``GATE_PARAMS`` table from
``common.py`` as JSON to stdout. Consumed by tests/Feature/GateParityTest.php
to assert that the PHP ``GateType`` / ``GateShape`` enums and the Python gate
table never drift apart. Deliberately imports nothing from the Braket SDK so
it runs on a bare Python installation.

Output (JSON on stdout)::

    {"gates": {"h": {"qubits": ["target"], "angles": []}, ...}}
"""

import json
import sys

from common import GATE_PARAMS


def main() -> None:
    """Entry point: drain stdin, write the gate table as JSON to stdout."""
    sys.stdin.read()

    gates = {
        gate_type: {"qubits": list(spec["qubits"]), "angles": list(spec["angles"])}
        for gate_type, spec in GATE_PARAMS.items()
    }

    print(json.dumps({"gates": gates}))


if __name__ == "__main__":
    main()
