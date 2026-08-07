"""Checks an extraction before any of it is stored.

The division of labour matters here. This module rejects things that are wrong
*about the document* — a report with no region, a coverage week in the future,
a table that mostly failed to parse. It does not decide whether a price is
reasonable in the market sense; that judgement belongs to the platform, and
duplicating it would give two places to keep in step.

A malformed table is a hard failure rather than a partial import. The
alternative — storing whatever parsed — is how a layout change becomes a week
of quietly missing areas that nobody notices until a chart looks wrong.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta

from extractor import ExtractedReport
from logger import get_logger
from settings import Settings, get_settings

log = get_logger(__name__)

#: The DOE publishes a week at a time. A span far from seven days means the
#: coverage line was misread, and every duplicate check against it would be
#: wrong.
_MIN_COVERAGE_DAYS = 5
_MAX_COVERAGE_DAYS = 10

#: How far ahead of today a coverage week may start. The report for a week is
#: published at its start, and clock skew between here and the DOE is worth a
#: couple of days of slack — but a date years out is a parse error.
_MAX_FUTURE_DAYS = 14


@dataclass
class ValidationResult:
    """What validation found. `errors` blocks the import; `warnings` do not."""

    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    valid_rows: int = 0
    rejected_rows: int = 0

    @property
    def ok(self) -> bool:
        return not self.errors

    def summary(self) -> str:
        return (
            f"valid={self.valid_rows} rejected={self.rejected_rows} "
            f"errors={len(self.errors)} warnings={len(self.warnings)}"
        )


def validate(report: ExtractedReport, settings: Settings | None = None) -> ValidationResult:
    """Check a report and count how much of it is usable."""
    settings = settings or get_settings()
    result = ValidationResult()

    _validate_identity(report, result)
    _validate_dates(report, result, settings)
    _validate_prices(report, result, settings)

    log.info("Validation: %s", result.summary())

    return result


def _validate_identity(report: ExtractedReport, result: ValidationResult) -> None:
    if not report.region:
        # Without a region the report cannot be filed or deduplicated, and two
        # regions' tables would merge into one.
        result.errors.append("No region could be read from the document.")

    if not report.prices:
        result.errors.append("The document yielded no prices.")

    if not report.areas:
        result.errors.append("The document yielded no areas.")


def _validate_dates(report: ExtractedReport, result: ValidationResult, settings: Settings) -> None:
    start, end = report.coverage_start, report.coverage_end

    if start is None or end is None:
        result.errors.append("No coverage week could be read from the document.")
        return

    if end < start:
        result.errors.append(f"Coverage runs backwards: {start} to {end}.")
        return

    span = (end - start).days + 1

    if not _MIN_COVERAGE_DAYS <= span <= _MAX_COVERAGE_DAYS:
        result.errors.append(
            f"Coverage spans {span} days ({start} to {end}); expected about a week."
        )

    today = date.today()

    if start > today + timedelta(days=_MAX_FUTURE_DAYS):
        result.errors.append(f"Coverage starts {start}, too far in the future to be real.")

    if report.monitoring_date and not (start - timedelta(days=7) <= report.monitoring_date <= end):
        # Not fatal — the monitoring date is informational — but a monitoring
        # date outside its own coverage week means one of the two was misread.
        result.warnings.append(
            f"Monitoring date {report.monitoring_date} lies outside {start}..{end}."
        )


def _validate_prices(report: ExtractedReport, result: ValidationResult, settings: Settings) -> None:
    seen: set[tuple[str, str, str | None]] = set()

    for price in report.prices:
        key = (price.area, price.product, price.brand)

        if key in seen:
            # One area cannot publish two ranges for one brand and product. It
            # means two blocks were merged or a label was attached twice.
            result.warnings.append(
                f"Duplicate row for {price.area} / {price.product} / {price.brand}."
            )

        seen.add(key)

        values = [value for value in (price.min_price, price.max_price) if value is not None]

        if not values:
            result.rejected_rows += 1
            continue

        out_of_range = [
            value
            for value in values
            if not settings.min_plausible_price <= value <= settings.max_plausible_price
        ]

        if out_of_range:
            result.rejected_rows += 1
            result.warnings.append(
                f"{price.area} / {price.product} / {price.brand}: "
                f"{out_of_range} outside the plausible range."
            )
            continue

        if (
            price.min_price is not None
            and price.max_price is not None
            and price.min_price > price.max_price
        ):
            # Reading a range in the wrong order swaps its ends, which is
            # invisible in any single figure and wrong in every average.
            result.rejected_rows += 1
            result.warnings.append(
                f"{price.area} / {price.product} / {price.brand}: "
                f"minimum {price.min_price} exceeds maximum {price.max_price}."
            )
            continue

        result.valid_rows += 1

    total = result.valid_rows + result.rejected_rows

    if total and (result.valid_rows / total) < settings.min_valid_row_ratio:
        # A table that mostly failed is a layout change, not a bad week. Storing
        # the fraction that parsed would leave a report that looks complete.
        result.errors.append(
            f"Only {result.valid_rows} of {total} rows are usable, below the "
            f"{settings.min_valid_row_ratio:.0%} threshold. The layout has probably changed."
        )
