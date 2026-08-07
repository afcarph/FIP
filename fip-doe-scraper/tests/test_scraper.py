"""End-to-end through the replay path, plus the run log.

These exercise :func:`scraper.run` with the browser replaced by captured
payloads. Everything after collection — mapping, parsing, the write path and
the run log — is the same code the scheduled run uses.
"""

from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path
from typing import Any

import pytest
from sqlalchemy import create_engine, func, select
from sqlalchemy.orm import sessionmaker

import database
import scraper
from models import Base, FuelPriceHistory, FuelStation, ScraperLog, utcnow
from settings import get_settings

SCHEMA_FIELDS = [
    ("qt_fgaojmiemc", "Timestamp"),
    ("qt_85e4fhiemc", "Gas Station"),
    ("qt_a1b2c3diemc", "Company"),
    ("qt_d4e5f6giemc", "City/Municipality"),
    ("qt_m4n5o6piemc", "RON 91"),
    ("qt_p7q8r9siemc", "RON 95"),
    ("qt_y7z8a9biemc", "Diesel"),
]


@pytest.fixture
def sqlite_backend(tmp_path: Path) -> Any:
    """Point the data layer at a file-backed SQLite database.

    A file rather than ``:memory:`` because ``scraper.run`` opens several
    sessions, and an in-memory database is per-connection.
    """
    engine = create_engine(f"sqlite+pysqlite:///{tmp_path / 'scraper.db'}", future=True)
    Base.metadata.create_all(engine)

    original_engine = database._engine
    original_factory = database._SessionFactory

    database._engine = engine
    database._SessionFactory = sessionmaker(bind=engine, expire_on_commit=False, future=True)

    yield engine

    database._engine = original_engine
    database._SessionFactory = original_factory
    engine.dispose()


def write_captures(directory: Path, price_date: str, rows: list[tuple]) -> None:
    """Lay down a getSchema and a batchedDataV2 capture for the replay path."""
    directory.mkdir(parents=True, exist_ok=True)
    for stale in directory.glob("*.json"):
        stale.unlink()

    field_ids = [field_id for field_id, _ in SCHEMA_FIELDS]

    def column(kind: str, values: list) -> dict:
        return {
            kind: {
                "values": [value for value in values if value is not None],
                "nullIndex": [index for index, value in enumerate(values) if value is None],
            }
        }

    payload = {
        "dataResponse": [
            {
                "dataSubset": [
                    {
                        "dataSubsetRequest": {"requestedFields": field_ids},
                        "dataset": {
                            "tableDataset": {
                                "column": [
                                    column("stringColumn", [price_date] * len(rows)),
                                    column("stringColumn", [row[0] for row in rows]),
                                    column("stringColumn", [row[1] for row in rows]),
                                    column("stringColumn", [row[2] for row in rows]),
                                    column("doubleColumn", [row[3] for row in rows]),
                                    column("doubleColumn", [row[4] for row in rows]),
                                    column("doubleColumn", [row[5] for row in rows]),
                                ]
                            }
                        },
                    }
                ]
            }
        ]
    }

    (directory / "run-getSchema-01.json").write_text(
        json.dumps({"fields": [{"name": fid, "label": lbl} for fid, lbl in SCHEMA_FIELDS]})
    )
    (directory / "run-batchedDataV2-01.json").write_text(json.dumps(payload))


def fast_failing_settings() -> Any:
    """Settings for the failure tests, with the retry backoff removed.

    The real delay is 5s, 10s between attempts — correct in production and
    thirty seconds of sleeping in a test suite.
    """
    return get_settings().model_copy(update={"max_attempts": 1, "retry_base_delay_s": 0.0})


DAY_ONE = [
    ("Petron EDSA", "Petron", "Quezon City", 58.20, 62.40, 56.85),
    ("Shell Ortigas", "Shell", "Pasig", None, 63.10, 57.40),
    ("SEAOIL Pasig", "SEAOIL", "Pasig", 57.60, 61.85, 56.20),
]


def counts(engine: Any) -> tuple[int, int]:
    with sessionmaker(bind=engine, future=True)() as session:
        return (
            session.scalar(select(func.count()).select_from(FuelStation)),
            session.scalar(select(func.count()).select_from(FuelPriceHistory)),
        )


class TestReplayRun:
    def test_a_first_run_imports_everything(self, sqlite_backend: Any, tmp_path: Path) -> None:
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        result = scraper.run(replay_dir=captures)

        assert result.inserted == 3
        assert result.stations_created == 3
        assert counts(sqlite_backend) == (3, 3)

    def test_re_running_the_same_day_changes_nothing(
        self, sqlite_backend: Any, tmp_path: Path
    ) -> None:
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        scraper.run(replay_dir=captures)
        second = scraper.run(replay_dir=captures)

        assert second.inserted == 0
        assert second.updated == 0
        assert second.skipped == 3
        assert counts(sqlite_backend) == (3, 3)

    def test_a_second_day_adds_history_without_touching_the_first(
        self, sqlite_backend: Any, tmp_path: Path
    ) -> None:
        captures = tmp_path / "captures"

        write_captures(captures, "20260806", DAY_ONE)
        scraper.run(replay_dir=captures)

        moved = [(*DAY_ONE[0][:4], 63.00, DAY_ONE[0][5]), *DAY_ONE[1:]]
        write_captures(captures, "20260807", moved)
        scraper.run(replay_dir=captures)

        # Six rows, not three: the earlier day is still there.
        assert counts(sqlite_backend) == (3, 6)

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            prices = session.scalars(
                select(FuelPriceHistory.ron95)
                .join(FuelStation, FuelStation.id == FuelPriceHistory.station_id)
                .where(FuelStation.company == "Petron")
                .order_by(FuelPriceHistory.price_date)
            ).all()

        assert [float(price) for price in prices] == [62.40, 63.00]

    def test_a_missing_grade_stays_missing(self, sqlite_backend: Any, tmp_path: Path) -> None:
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        scraper.run(replay_dir=captures)

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            row = session.scalars(
                select(FuelPriceHistory)
                .join(FuelStation, FuelStation.id == FuelPriceHistory.station_id)
                .where(FuelStation.company == "Shell")
            ).one()

        # Shell reported no RON 91. Storing a zero, or borrowing the next
        # station's value, would both be worse than the gap.
        assert row.ron91 is None
        assert float(row.ron95) == 63.10

    def test_a_dry_run_writes_no_prices(self, sqlite_backend: Any, tmp_path: Path) -> None:
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        result = scraper.run(replay_dir=captures, dry_run=True)

        assert result.processed == 3
        assert counts(sqlite_backend) == (0, 0)

    def test_an_empty_capture_directory_fails_the_run(
        self, sqlite_backend: Any, tmp_path: Path
    ) -> None:
        empty = tmp_path / "empty"
        empty.mkdir()

        result = scraper.run(replay_dir=empty, settings=fast_failing_settings())

        assert result.errors
        assert counts(sqlite_backend) == (0, 0)

    def test_it_retries_the_configured_number_of_times(
        self, sqlite_backend: Any, tmp_path: Path
    ) -> None:
        empty = tmp_path / "empty"
        empty.mkdir()

        settings = fast_failing_settings().model_copy(update={"max_attempts": 3})
        scraper.run(replay_dir=empty, settings=settings)

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            entry = session.scalars(select(ScraperLog)).one()

        assert entry.attempts == 3


class TestRunLog:
    def test_a_successful_run_is_recorded(self, sqlite_backend: Any, tmp_path: Path) -> None:
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        scraper.run(replay_dir=captures)

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            entry = session.scalars(select(ScraperLog)).one()

        assert entry.status == ScraperLog.STATUS_SUCCESS
        assert entry.records_inserted == 3
        assert entry.finished_at is not None
        assert entry.duration_seconds is not None

    def test_a_failed_run_is_still_recorded(self, sqlite_backend: Any, tmp_path: Path) -> None:
        # A log that only records successes cannot answer "when did this last
        # work?", which is the only question asked of it during an incident.
        empty = tmp_path / "empty"
        empty.mkdir()

        scraper.run(replay_dir=empty, settings=fast_failing_settings())

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            entry = session.scalars(select(ScraperLog)).one()

        assert entry.status == ScraperLog.STATUS_FAILED
        assert entry.errors
        assert entry.attempts >= 1

    def test_the_duration_is_computed_without_a_timezone_clash(
        self, sqlite_backend: Any, tmp_path: Path
    ) -> None:
        # Regression. started_at was written timezone-aware while the column
        # stores none, so it read back naive and the duration subtraction
        # raised TypeError — on the way out of every run, success or failure.
        captures = tmp_path / "captures"
        write_captures(captures, "20260806", DAY_ONE)

        scraper.run(replay_dir=captures)

        with sessionmaker(bind=sqlite_backend, future=True)() as session:
            entry = session.scalars(select(ScraperLog)).one()

        assert entry.duration_seconds is not None
        assert float(entry.duration_seconds) >= 0

    def test_utcnow_is_naive(self) -> None:
        # The property the fix depends on, asserted directly so a later change
        # back to an aware value fails here rather than in production.
        assert utcnow().tzinfo is None
        assert isinstance(utcnow(), datetime)
