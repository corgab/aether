"""Pytest configuration for the bin/python test suite.

Puts ``bin/python`` on ``sys.path`` so the scripts' modules (``common``,
``list_gates``, ...) import directly, exactly as they do when the scripts
run from their own directory via the PHP ``PythonBridge``.
"""

import sys
from pathlib import Path

BIN_PYTHON = Path(__file__).resolve().parents[2] / "bin" / "python"

if str(BIN_PYTHON) not in sys.path:
    sys.path.insert(0, str(BIN_PYTHON))
