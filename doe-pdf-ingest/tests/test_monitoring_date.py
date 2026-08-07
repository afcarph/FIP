"""The date the DOE walked the forecourts.

Held apart from `test_extractor.py` because the bug this covers was not in the
reading of the line — that always worked — but in what the reader was allowed
to depend on. Both published layouts are exercised, since the whole defect was
that one of them was silently skipped.
"""

from __future__ import annotations

from datetime import date
from pathlib import Path

import pytest

from extractor import extract, monitoring_date, parse_header

NCR = Path(__file__).parent / "fixtures" / "ncr-price-monitoring-07282026.pdf"
VISAYAS = Path(__file__).parent / "fixtures" / "vfo-lf-price-monitoring-112525.pdf"


class TestBothPublishedLayouts:
    """Every imported report carries a monitoring date when the PDF states one."""

    @pytest.mark.parametrize(
        ("fixture", "expected_region", "expected_monitoring"),
        [
            (NCR, "NCR", "2026-07-28"),
            (VISAYAS, "REGIONS 6-8", "2025-11-25"),
        ],
    )
    def test_the_monitoring_date_is_read(
        self, fixture: Path, expected_region: str, expected_monitoring: str
    ) -> None:
        # The regression: every REGIONS 6-8 report stored a null monitoring
        # date, while the line was printed in the document all along.
        report = extract(fixture)

        assert report.region == expected_region
        assert report.monitoring_date is not None
        assert report.monitoring_date.isoformat() == expected_monitoring

    @pytest.mark.parametrize("fixture", [NCR, VISAYAS])
    def test_coverage_is_complete_too(self, fixture: Path) -> None:
        report = extract(fixture)

        assert report.coverage_start is not None
        assert report.coverage_end is not None
        assert report.coverage_start < report.coverage_end


class TestTheYear:
    def test_it_is_taken_from_the_line_when_printed(self) -> None:
        # The Visayas layout prints this on its last page and states no
        # coverage week anywhere, so the line's own year is the only one there
        # is. Borrowing a year from coverage cannot work here.
        assert monitoring_date("Date of Monitoring: August 04-10, 2026") == date(2026, 8, 4)

    def test_it_falls_back_to_the_coverage_year_when_absent(self) -> None:
        assert monitoring_date(
            "Date of Monitoring: July 28-31", fallback_year=2026
        ).isoformat() == "2026-07-28"

    def test_a_line_with_no_year_and_no_coverage_yields_nothing(self) -> None:
        # Absent beats invented: a monitoring date guessed into the wrong year
        # fails validation against the coverage week and fails the whole report
        # with it.
        assert monitoring_date("Date of Monitoring: July 28-31") is None

    def test_a_document_that_states_no_monitoring_date_yields_nothing(self) -> None:
        assert monitoring_date("(For the week: Tuesday - Monday)", fallback_year=2026) is None


class TestItDoesNotDependOnCoverageBeingStated:
    def test_the_line_is_read_even_with_no_coverage_in_the_document(self) -> None:
        # parse_header returns no coverage for this text, which is precisely
        # the case that used to discard the monitoring date.
        text = (
            "REGIONS 6-8 & NIR\n"
            "(For the week: Tuesday - Monday)\n"
            "Date of Monitoring: August 04-10, 2026"
        )
        _, start, end, monitoring = parse_header(text)

        assert start is None and end is None
        assert monitoring is not None
        assert monitoring.isoformat() == "2026-08-04"


class TestItSurvivesValidation:
    def test_the_monitoring_date_sits_inside_the_covered_week(self) -> None:
        # The validator rejects a monitoring date outside the coverage window,
        # so reading one wrongly is worse than reading none: it takes the whole
        # report down.
        for fixture in (NCR, VISAYAS):
            report = extract(fixture)

            assert report.coverage_start is not None
            assert report.monitoring_date is not None
            assert report.coverage_start <= report.monitoring_date <= report.coverage_end
