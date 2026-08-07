"""Shared fixtures.

Database tests run against in-memory SQLite. The models use no MySQL-specific
types, and the logic under test — change detection, the unique key, the merge —
is the same on either engine. A MySQL container per test run would be slower
without testing anything the application code does differently.

The one behaviour SQLite does *not* reproduce is the unique constraint's
interaction with concurrent writers. That is covered by the constraint itself,
which is declared in the model and created by ``init_db`` on whatever engine.
"""

from __future__ import annotations

import sys
from collections.abc import Iterator
from datetime import date
from pathlib import Path
from typing import Any

import pytest
from sqlalchemy import create_engine
from sqlalchemy.orm import Session, sessionmaker

# The modules are flat at the project root, which is how they are run in the
# container. Tests need the same import path.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from models import Base

FIXTURE_DIR = Path(__file__).parent / "fixtures"


@pytest.fixture
def session() -> Iterator[Session]:
    """A clean database per test."""
    engine = create_engine("sqlite+pysqlite:///:memory:", future=True)
    Base.metadata.create_all(engine)

    factory = sessionmaker(bind=engine, expire_on_commit=False, future=True)
    db = factory()

    try:
        yield db
        db.commit()
    finally:
        db.close()
        engine.dispose()


@pytest.fixture
def labels() -> dict[str, str]:
    """A realistic getSchema result: generated ids against published labels.

    The ids are the shape Looker actually emits. They are fixture data, not
    configuration — nothing in the application knows them.
    """
    return {
        "qt_fgaojmiemc": "Timestamp",
        "qt_85e4fhiemc": "Gas Station",
        "qt_a1b2c3diemc": "Company",
        "qt_d4e5f6giemc": "City/Municipality",
        "qt_g7h8i9jiemc": "Province",
        "qt_j1k2l3miemc": "Barangay",
        "qt_m4n5o6piemc": "RON 91",
        "qt_p7q8r9siemc": "RON 95",
        "qt_s1t2u3viemc": "RON 97",
        "qt_v4w5x6yiemc": "RON 100",
        "qt_y7z8a9biemc": "Diesel",
        "qt_b1c2d3eiemc": "Diesel Plus",
        "qt_e4f5g6hiemc": "Latitude",
        "qt_h7i8j9kiemc": "Longitude",
    }


def make_dataset(
    field_ids: list[str],
    columns: list[dict[str, Any]],
) -> dict[str, Any]:
    """Build one ``dataResponse`` element around a set of typed columns.

    Mirrors the nesting Looker produces: the ids sit beside the dataset in the
    request echo, not inside it.
    """
    return {
        "dataSubset": [
            {
                "dataSubsetRequest": {"requestedFields": field_ids},
                "dataset": {"tableDataset": {"column": columns}},
            }
        ]
    }


def string_column(values: list[Any], nulls: list[int] | None = None) -> dict[str, Any]:
    """A Looker string column, with values omitted at the masked positions."""
    return {
        "stringColumn": {
            "values": [value for value in values if value is not None],
            "nullIndex": nulls if nulls is not None else _null_positions(values),
        }
    }


def double_column(values: list[Any], nulls: list[int] | None = None) -> dict[str, Any]:
    """A Looker numeric column, with the same omission rule."""
    return {
        "doubleColumn": {
            "values": [value for value in values if value is not None],
            "nullIndex": nulls if nulls is not None else _null_positions(values),
        }
    }


def _null_positions(values: list[Any]) -> list[int]:
    return [index for index, value in enumerate(values) if value is None]


@pytest.fixture
def sample_payload(labels: dict[str, str]) -> dict[str, Any]:
    """A three-row payload with nulls in the middle of two price columns.

    The nulls are the point. Row 1 has no RON 97 and row 2 has no RON 91, so a
    parser that ignores the null mask produces plausible, wrong prices rather
    than an error.
    """
    field_ids = [
        "qt_fgaojmiemc",  # Timestamp
        "qt_85e4fhiemc",  # Gas Station
        "qt_a1b2c3diemc",  # Company
        "qt_d4e5f6giemc",  # City
        "qt_m4n5o6piemc",  # RON 91
        "qt_p7q8r9siemc",  # RON 95
        "qt_s1t2u3viemc",  # RON 97
        "qt_y7z8a9biemc",  # Diesel
    ]

    return {
        "dataResponse": [
            make_dataset(
                field_ids,
                [
                    string_column(["20260806", "20260806", "20260806"]),
                    string_column(["Petron EDSA", "Shell Ortigas", "SEAOIL Pasig"]),
                    string_column(["Petron", "Shell", "SEAOIL"]),
                    string_column(["Quezon City", "Pasig", "Pasig"]),
                    double_column([58.20, None, 57.60]),
                    double_column([62.40, 63.10, 61.85]),
                    double_column([None, 66.30, None]),
                    double_column([56.85, 57.40, 56.20]),
                ],
            )
        ]
    }


@pytest.fixture
def expected_first_row() -> dict[str, Any]:
    """What the first row of ``sample_payload`` must parse to."""
    return {
        "station": "Petron EDSA",
        "company": "Petron",
        "city": "Quezon City",
        "price_date": date(2026, 8, 6),
        "ron91": 58.20,
        "ron95": 62.40,
        "ron97": None,
        "diesel": 56.85,
    }
