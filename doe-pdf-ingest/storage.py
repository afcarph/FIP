"""Engine, sessions and the write path.

The rules this module enforces:

  * A report is identified by its checksum. Re-importing the same PDF is a
    no-op, which is what makes the daily schedule safe to run twice.
  * A region publishes one report per week. A re-issued PDF with different
    bytes is a *correction* to that week, not a second report — it replaces the
    stored one rather than sitting beside it.
  * A report's price rows are rewritten wholesale, never merged. A correction
    that drops an area must not leave the old area's figures behind.
"""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass
from decimal import Decimal

from sqlalchemy import create_engine, delete, select
from sqlalchemy.engine import Engine
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.orm import Session, sessionmaker

from extractor import ExtractedReport
from logger import current_run_id, get_logger
from models import FuelPrice, FuelReport, ImportRun, utcnow
from settings import get_settings

log = get_logger(__name__)

_engine: Engine | None = None
_SessionFactory: sessionmaker[Session] | None = None


def get_engine() -> Engine:
    """The process-wide engine, created on first use."""
    global _engine

    if _engine is None:
        settings = get_settings()
        _engine = create_engine(
            settings.database_url,
            pool_pre_ping=settings.db_pool_pre_ping,
            pool_recycle=settings.db_pool_recycle,
            echo=settings.db_echo,
            future=True,
            pool_size=5,
            max_overflow=5,
        )
        log.info("Database engine ready", extra={"dsn": settings.safe_database_url()})

    return _engine


def get_session_factory() -> sessionmaker[Session]:
    global _SessionFactory

    if _SessionFactory is None:
        _SessionFactory = sessionmaker(bind=get_engine(), expire_on_commit=False, future=True)

    return _SessionFactory


@contextmanager
def session_scope() -> Iterator[Session]:
    """A transactional session: commit on success, roll back on anything else."""
    session = get_session_factory()()

    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def reset_engine() -> None:
    """Drop the cached engine. Used by tests that swap the database URL."""
    global _engine, _SessionFactory

    if _engine is not None:
        _engine.dispose()

    _engine = None
    _SessionFactory = None


@dataclass
class StoreResult:
    """What storing one report did."""

    report_id: int | None = None
    rows_written: int = 0
    rows_replaced: int = 0
    skipped: bool = False
    reason: str | None = None

    @property
    def stored(self) -> bool:
        return self.report_id is not None and not self.skipped


def is_duplicate(session: Session, checksum: str) -> bool:
    """Whether this exact PDF has already been imported."""
    return (
        session.scalar(select(FuelReport.id).where(FuelReport.checksum == checksum).limit(1))
        is not None
    )


def _decimal(value: float | None) -> Decimal | None:
    return None if value is None else Decimal(str(round(value, 2)))


def store_report(
    session: Session,
    report: ExtractedReport,
    *,
    checksum: str,
    filename: str,
    source_url: str | None,
    pdf_path: str | None,
) -> StoreResult:
    """Write a report and its prices.

    Assumes the report has already passed validation — this module does not
    re-check what `validator` checked, so the two cannot disagree.
    """
    if is_duplicate(session, checksum):
        return StoreResult(skipped=True, reason="This PDF has already been imported.")

    assert report.region is not None  # guaranteed by validation
    assert report.coverage_start is not None
    assert report.coverage_end is not None

    existing = session.scalar(
        select(FuelReport)
        .where(FuelReport.region == report.region)
        .where(FuelReport.coverage_start == report.coverage_start)
    )

    replaced = 0

    if existing is not None:
        # Same region, same week, different bytes: the DOE re-issued the
        # document. That is a correction, and the corrected table replaces the
        # old one — merging would leave figures the DOE has withdrawn.
        replaced = len(
            session.scalars(select(FuelPrice.id).where(FuelPrice.report_id == existing.id)).all()
        )

        session.execute(delete(FuelPrice).where(FuelPrice.report_id == existing.id))

        entry = existing
        entry.checksum = checksum
        entry.pdf_filename = filename
        entry.pdf_path = pdf_path
        entry.source_url = source_url
        entry.updated_at = utcnow()
        log.info(
            "Replacing %s for the week of %s (re-issued document)",
            report.region,
            report.coverage_start,
        )
    else:
        entry = FuelReport(
            region=report.region,
            coverage_start=report.coverage_start,
            coverage_end=report.coverage_end,
            checksum=checksum,
            pdf_filename=filename,
            pdf_path=pdf_path,
            source_url=source_url,
        )
        session.add(entry)

    entry.monitoring_date = report.monitoring_date
    # The DOE does not print a publication date; the coverage week begins when
    # the report is published, which is the closest honest value.
    entry.publication_date = report.coverage_start
    entry.coverage_end = report.coverage_end
    entry.extractor = report.extractor
    entry.quality = _decimal(report.quality)
    entry.areas_count = len(report.areas)
    entry.rows_count = len(report.prices)

    session.flush()

    written = 0

    for price in report.prices:
        session.add(
            FuelPrice(
                report_id=entry.id,
                area=price.area,
                province=price.province,
                product=price.product,
                fuel_code=price.fuel_code,
                brand=price.brand,
                min_price=_decimal(price.min_price),
                max_price=_decimal(price.max_price),
                common_price=_decimal(price.common_price),
            )
        )
        written += 1

    log.info(
        "Stored %s %s: %d rows across %d areas",
        report.region,
        report.coverage_start,
        written,
        len(report.areas),
    )

    return StoreResult(
        report_id=entry.id,
        rows_written=written,
        rows_replaced=replaced,
    )


# --- run log -----------------------------------------------------------------


def start_run() -> int:
    """Open a `running` row and return its id.

    Committed immediately in its own transaction, so a run that dies part way
    still leaves evidence it started.
    """
    with session_scope() as session:
        run = ImportRun(
            started_at=utcnow(), status=ImportRun.STATUS_RUNNING, run_id=current_run_id()
        )
        session.add(run)
        session.flush()

        return run.id


def finish_run(
    run_id: int, *, status: str, counts: dict[str, int | str | None], errors: list[str]
) -> None:
    """Close out a run log.

    Failures here are swallowed. The exit code and stderr already carry the
    outcome, and a database that has gone away should not turn a completed
    import into a crash on the way out.
    """
    try:
        with session_scope() as session:
            run = session.get(ImportRun, run_id)

            if run is None:
                log.warning("Run log %s vanished before it could be closed", run_id)
                return

            finished = utcnow()
            run.finished_at = finished
            run.duration_seconds = Decimal(
                str(round((finished - run.started_at).total_seconds(), 3))
            )
            run.status = status

            # Only fields the model has. The ingest and the Laravel migration
            # deploy separately, so a counter added ahead of its column is
            # dropped rather than crashing the run that reports it.
            for field, value in counts.items():
                if hasattr(run, field):
                    setattr(run, field, value)
                else:
                    log.debug("Run log has no column %s; skipping", field)

            if errors:
                # Bounded: a layout change produces one error per row, and a
                # multi-megabyte TEXT write is its own incident.
                head = errors[:50]
                suffix = f"\n… and {len(errors) - 50} more" if len(errors) > 50 else ""
                run.errors = "\n".join(head) + suffix

    except SQLAlchemyError:
        log.exception("Could not write the run log")


def last_successful_run() -> ImportRun | None:
    """The most recent run that completed, for health checks and the dashboard."""
    with session_scope() as session:
        return session.scalar(
            select(ImportRun)
            .where(
                ImportRun.status.in_(
                    [
                        ImportRun.STATUS_SUCCESS,
                        ImportRun.STATUS_PARTIAL,
                        ImportRun.STATUS_NO_CHANGES,
                    ]
                )
            )
            .order_by(ImportRun.started_at.desc())
            .limit(1)
        )
