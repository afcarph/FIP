"""Shared fixtures.

The module under test lives at the project root, which is how it is imported in
the container; tests need the same path.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

FIXTURE_DIR = Path(__file__).parent / 'fixtures'
