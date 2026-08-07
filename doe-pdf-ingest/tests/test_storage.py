"""Validation and the write path.

Database tests run against in-memory SQLite. The models use no MySQL-specific
types, and the rules under test — duplicate rejection, correction-replaces-week,
wholesale row rewrite — behave identically on either engine.
"""

from __future__ import annotations

from collections.abc import Iterator
from datetime import date, timedelta
from pathlib import Path
from typing import Any

import pytest
from sqlalchemy import create_engine, func, select
from sqlalchemy.orm import Session, sessionmaker

import storage
from extractor import AreaPrice, ExtractedReport, extract_with_pdfplumber
from models import Base, FuelPrice, FuelReport, ImportRun
from settings import get_settings
from validator import validate

FIXTURE = Path(__file__).parent / "fixtures" / "ncr-price-monitoring-07282026.pdf"


@pytest.fixture
def session(tmp_path: Path) -> Iterator[Session]:
    """A clean database, wired into the storage module."""
    engine = create_engine(f"sqlite+pysqlite:///{tmp_path / 't.db'}", future=True)
    Base.metadata.create_all(engine)

    original_engine, original_factory = storage._engine, storage._SessionFactory
    storage._engine = engine
    storage._SessionFactory = sessionmaker(bind=engine, expire_on_commit=False, future=True)

    db = storage._SessionFactory()

    try:
        yield db
    finally:
        db.close()
        storage._engine, storage._SessionFactory = original_engine, original_factory
        engine.dispose()


def a_report(**overrides: Any) -> ExtractedReport:
    """A small, valid extraction."""
    defaults = {
        "region": "NCR",
        "coverage_start": date(2026, 7, 28),
        "coverage_end": date(2026, 8, 3),
        "monitoring_date": date(2026, 7, 28),
    }
    defaults.update(overrides)

    report = ExtractedReport(**defaults)
    report.prices = overrides.get(
        "prices",
        [
            AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 79.5, 87.5),
            AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", None, 71.2, 95.3, 87.1),
            AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
        ],
    )
    report.quality = 0.95
    report.extractor = "pdfplumber-coordinates"

    return report


def store(session: Session, report: ExtractedReport, checksum: str) -> Any:
    return storage.store_report(
        session,
        report,
        checksum=checksum,
        filename=f"{checksum[:8]}.pdf",
        source_url="https://example.test/doc",
        pdf_path=f"/tmp/{checksum[:8]}.pdf",
    )


class TestValidation:
    def test_the_real_report_validates(self) -> None:
        report = extract_with_pdfplumber(FIXTURE, get_settings())

        assert validate(report).ok

    def test_a_report_without_a_region_is_rejected(self) -> None:
        # Without one it cannot be filed, and two regions' tables would merge.
        result = validate(a_report(region=None))

        assert not result.ok
        assert any("region" in error for error in result.errors)

    def test_a_report_without_a_coverage_week_is_rejected(self) -> None:
        result = validate(a_report(coverage_start=None, coverage_end=None))

        assert not result.ok

    def test_a_coverage_week_that_runs_backwards_is_rejected(self) -> None:
        result = validate(a_report(coverage_start=date(2026, 8, 3), coverage_end=date(2026, 7, 28)))

        assert not result.ok

    def test_a_coverage_span_that_is_not_a_week_is_rejected(self) -> None:
        # A 40-day span means the coverage line was misread, and every
        # duplicate check against it would be wrong.
        result = validate(a_report(coverage_end=date(2026, 9, 6)))

        assert not result.ok

    def test_a_coverage_week_far_in_the_future_is_rejected(self) -> None:
        start = date.today() + timedelta(days=400)
        result = validate(a_report(coverage_start=start, coverage_end=start + timedelta(days=6)))

        assert not result.ok

    def test_an_implausible_price_is_rejected_without_failing_the_report(self) -> None:
        result = validate(
            a_report(
                prices=[
                    AreaPrice(
                        "Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 79.5, 87.5
                    ),
                    AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Shell", 4.0, 9.0),
                    AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
                ]
            )
        )

        assert result.rejected_rows == 1
        assert result.valid_rows == 2
        assert result.ok

    def test_a_reversed_range_is_rejected(self) -> None:
        # Invisible in any single figure and wrong in every average.
        result = validate(
            a_report(
                prices=[
                    AreaPrice(
                        "Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 95.0, 79.0
                    ),
                    AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
                ]
            )
        )

        assert result.rejected_rows == 1

    def test_a_mostly_unusable_table_fails_the_whole_report(self) -> None:
        # A layout change, not a bad week. Storing the fraction that parsed
        # leaves a report that looks complete.
        result = validate(
            a_report(
                prices=[
                    AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 1.0, 2.0),
                    AreaPrice("Quezon City", None, "RON 91", "gasoline_ron91", "Shell", 1.0, 2.0),
                    AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
                ]
            )
        )

        assert not result.ok
        assert any("layout" in error for error in result.errors)


class TestStorage:
    def test_it_stores_a_report_and_its_prices(self, session: Session) -> None:
        result = store(session, a_report(), "a" * 64)
        session.commit()

        assert result.stored
        assert result.rows_written == 3
        assert session.scalar(select(func.count()).select_from(FuelReport)) == 1
        assert session.scalar(select(func.count()).select_from(FuelPrice)) == 3

    def test_the_same_pdf_is_not_imported_twice(self, session: Session) -> None:
        store(session, a_report(), "a" * 64)
        session.commit()

        result = store(session, a_report(), "a" * 64)

        assert result.skipped
        assert session.scalar(select(func.count()).select_from(FuelReport)) == 1

    def test_a_reissued_document_corrects_the_week_rather_than_adding_one(
        self, session: Session
    ) -> None:
        # Different bytes, same region and week: the DOE re-issued it. Two rows
        # for one week would double every average computed over them.
        store(session, a_report(), "a" * 64)
        session.commit()

        corrected = a_report(
            prices=[
                AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 80.0, 88.0),
            ]
        )
        result = store(session, corrected, "b" * 64)
        session.commit()

        assert session.scalar(select(func.count()).select_from(FuelReport)) == 1
        assert result.rows_replaced == 3
        assert session.scalar(select(func.count()).select_from(FuelPrice)) == 1

    def test_a_correction_does_not_leave_withdrawn_figures_behind(self, session: Session) -> None:
        store(session, a_report(), "a" * 64)
        session.commit()

        store(
            session,
            a_report(
                prices=[
                    AreaPrice(
                        "Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 80.0, 88.0
                    ),
                ]
            ),
            "b" * 64,
        )
        session.commit()

        remaining = session.scalars(select(FuelPrice.brand)).all()

        assert remaining == ["Petron"]

    def test_a_different_week_is_a_separate_report(self, session: Session) -> None:
        store(session, a_report(), "a" * 64)
        store(
            session,
            a_report(
                coverage_start=date(2026, 8, 4),
                coverage_end=date(2026, 8, 10),
            ),
            "b" * 64,
        )
        session.commit()

        assert session.scalar(select(func.count()).select_from(FuelReport)) == 2

    def test_a_different_region_in_the_same_week_is_a_separate_report(
        self, session: Session
    ) -> None:
        store(session, a_report(), "a" * 64)
        store(session, a_report(region="Region IV-A (CALABARZON)"), "b" * 64)
        session.commit()

        assert session.scalar(select(func.count()).select_from(FuelReport)) == 2

    def test_the_overall_row_is_stored_with_its_common_price(self, session: Session) -> None:
        store(session, a_report(), "a" * 64)
        session.commit()

        overall = session.scalar(select(FuelPrice).where(FuelPrice.brand.is_(None)))

        assert float(overall.common_price) == 87.1

    def test_the_report_records_which_extractor_won(self, session: Session) -> None:
        # A report imported at 0.6 is worth looking at before one at 0.98.
        store(session, a_report(), "a" * 64)
        session.commit()

        entry = session.scalar(select(FuelReport))

        assert entry.extractor == "pdfplumber-coordinates"
        assert float(entry.quality) == 0.95

    def test_the_pdf_path_is_kept_so_it_can_be_replayed(self, session: Session) -> None:
        store(session, a_report(), "a" * 64)
        session.commit()

        assert session.scalar(select(FuelReport)).pdf_path is not None


class TestRunLog:
    def test_a_run_is_recorded_from_the_moment_it_starts(self, session: Session) -> None:
        run_id = storage.start_run()

        entry = session.get(ImportRun, run_id)

        assert entry.status == ImportRun.STATUS_RUNNING

    def test_finishing_a_run_records_its_counts_and_duration(self, session: Session) -> None:
        run_id = storage.start_run()

        storage.finish_run(
            run_id,
            status=ImportRun.STATUS_SUCCESS,
            counts={"reports_imported": 2, "records_imported": 300},
            errors=[],
        )
        session.expire_all()

        entry = session.get(ImportRun, run_id)

        assert entry.status == ImportRun.STATUS_SUCCESS
        assert entry.reports_imported == 2
        assert entry.records_imported == 300
        assert entry.duration_seconds is not None

    def test_a_quiet_week_is_recorded_as_its_own_status(self, session: Session) -> None:
        # Nothing published is a success. Recorded distinctly so it cannot be
        # confused with a scheduler that died.
        run_id = storage.start_run()
        storage.finish_run(run_id, status=ImportRun.STATUS_NO_CHANGES, counts={}, errors=[])
        session.expire_all()

        assert session.get(ImportRun, run_id).status == ImportRun.STATUS_NO_CHANGES

    def test_errors_are_bounded(self, session: Session) -> None:
        # A layout change produces one error per row; a multi-megabyte TEXT
        # write is its own incident.
        run_id = storage.start_run()
        storage.finish_run(
            run_id,
            status=ImportRun.STATUS_FAILED,
            counts={},
            errors=[f"row {index} failed" for index in range(500)],
        )
        session.expire_all()

        errors = session.get(ImportRun, run_id).errors

        assert "and 450 more" in errors
        assert len(errors) < 5000


class TestValidationFilters:
    """Rejecting a row has to mean not storing it.

    The first live import against staging stored 104 rows that validation had
    counted as rejected — spreadsheet indices and out-of-range values — because
    the count was kept and the list was not.
    """

    def test_rejected_rows_are_not_in_the_accepted_set(self) -> None:
        result = validate(
            a_report(
                prices=[
                    AreaPrice(
                        "Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 79.5, 87.5
                    ),
                    AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Shell", 2.0, 8.0),
                    AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
                ]
            )
        )

        assert result.rejected_rows == 1
        assert len(result.accepted) == 2
        assert all(price.brand != "Shell" or price.min_price > 10 for price in result.accepted)

    def test_a_reversed_range_is_not_accepted(self) -> None:
        result = validate(
            a_report(
                prices=[
                    AreaPrice(
                        "Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 95.0, 79.0
                    ),
                    AreaPrice("Quezon City", None, "DIESEL", "diesel", "Shell", 92.8, 95.7),
                ]
            )
        )

        assert len(result.accepted) == 1

    def test_storing_the_accepted_set_keeps_implausible_values_out(self, session: Session) -> None:
        report = a_report(
            prices=[
                AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Petron", 79.5, 87.5),
                AreaPrice("Quezon City", None, "RON 95", "gasoline_ron95", "Shell", 2.0, 8.0),
            ]
        )
        report.prices = validate(report).accepted

        store(session, report, "c" * 64)
        session.commit()

        stored = session.scalars(select(FuelPrice.min_price)).all()

        assert [float(value) for value in stored] == [79.5]
