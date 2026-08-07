"""Engine, sessions and the write path.

The import rules the whole module exists to enforce:

* A station is identified by its fingerprint, not its display name.
* ``(station_id, price_date)`` is unique. Re-running a day is idempotent.
* A row is only written when a value actually changed. Rewriting equal values
  daily would leave ``updated_at`` useless as a signal and produce a binlog the
  size of the table for no information.
* Nothing is ever deleted.
"""

from __future__ import annotations

from collections.abc import Iterator, Sequence
from contextlib import contextmanager
from dataclasses import dataclass, field
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import create_engine, select
from sqlalchemy.engine import Engine
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.orm import Session, sessionmaker

from config import FUEL_COLUMNS
from logger import current_run_id, get_logger
from models import Base, FuelPriceHistory, FuelStation, ScraperLog, utcnow
from settings import get_settings

log = get_logger(__name__)

_engine: Engine | None = None
_SessionFactory: sessionmaker[Session] | None = None


# ---------------------------------------------------------------------------
# Engine and session
# ---------------------------------------------------------------------------


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
            # The scraper is one worker doing one thing. A large pool just
            # holds idle connections open against MySQL's limit all day.
            pool_size=5,
            max_overflow=5,
        )
        log.info("Database engine ready", extra={"dsn": settings.safe_database_url()})

    return _engine


def get_session_factory() -> sessionmaker[Session]:
    """The session factory bound to :func:`get_engine`."""
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


def init_db() -> None:
    """Create any missing tables.

    ``create_all`` only adds what is absent; it will not alter a table whose
    definition has drifted. Column changes are a migration, not a restart.
    """
    Base.metadata.create_all(bind=get_engine())
    log.info("Schema verified", extra={"tables": sorted(Base.metadata.tables)})


def reset_engine() -> None:
    """Drop the cached engine. Used by tests that swap the database URL."""
    global _engine, _SessionFactory

    if _engine is not None:
        _engine.dispose()

    _engine = None
    _SessionFactory = None


# ---------------------------------------------------------------------------
# Import
# ---------------------------------------------------------------------------


@dataclass
class ImportResult:
    """What one import did, for the run log and for the exit code."""

    processed: int = 0
    inserted: int = 0
    updated: int = 0
    skipped: int = 0
    stations_created: int = 0
    errors: list[str] = field(default_factory=list)

    @property
    def wrote_anything(self) -> bool:
        return bool(self.inserted or self.updated)

    def summary(self) -> str:
        return (
            f"processed={self.processed} inserted={self.inserted} "
            f"updated={self.updated} unchanged={self.skipped} "
            f"new_stations={self.stations_created} errors={len(self.errors)}"
        )


def _to_decimal(value: Any) -> Decimal | None:
    """Coerce a parsed price to Decimal, or ``None``."""
    if value is None:
        return None
    try:
        return Decimal(str(value))
    except (ArithmeticError, ValueError):
        return None


def _resolve_stations(
    session: Session, records: Sequence[dict[str, Any]]
) -> tuple[dict[str, FuelStation], int]:
    """Fetch or create a station per distinct fingerprint in ``records``.

    Done in one pass over the batch rather than per record: a national feed is
    ~10,000 rows across ~3,000 stations, and a per-record lookup turns one
    query into ten thousand.
    """
    wanted: dict[str, dict[str, Any]] = {}

    for record in records:
        fingerprint = FuelStation.make_fingerprint(
            company=record.get("company"),
            name=record.get("station"),
            city=record.get("city"),
            barangay=record.get("barangay"),
        )
        record["_fingerprint"] = fingerprint
        # Last occurrence wins, so a later row carrying coordinates upgrades an
        # earlier one that lacked them.
        wanted[fingerprint] = record

    existing = {
        station.fingerprint: station
        for station in session.scalars(
            select(FuelStation).where(FuelStation.fingerprint.in_(wanted.keys()))
        )
    }

    now = utcnow()
    created = 0

    for fingerprint, record in wanted.items():
        station = existing.get(fingerprint)

        if station is None:
            station = FuelStation(
                fingerprint=fingerprint,
                company=(record.get("company") or "Unknown").strip()[:120],
                name=(record.get("station") or None),
                region=record.get("region"),
                province=record.get("province"),
                city=record.get("city"),
                barangay=record.get("barangay"),
                address=record.get("address"),
                latitude=_to_decimal(record.get("latitude")),
                longitude=_to_decimal(record.get("longitude")),
                first_seen_at=now,
                last_seen_at=now,
            )
            session.add(station)
            existing[fingerprint] = station
            created += 1
            continue

        station.last_seen_at = now

        # Backfill only. A station that gains coordinates or an address should
        # keep them, but a feed that drops a field for a week must not blank
        # what we already know.
        for column in ("region", "province", "city", "barangay", "address"):
            if getattr(station, column) is None and record.get(column):
                setattr(station, column, record[column])

        if station.latitude is None and record.get("latitude") is not None:
            station.latitude = _to_decimal(record["latitude"])
        if station.longitude is None and record.get("longitude") is not None:
            station.longitude = _to_decimal(record["longitude"])

    # Assign primary keys without ending the transaction, so the price upsert
    # below can reference station.id.
    session.flush()

    return existing, created


def upsert_prices(session: Session, records: Sequence[dict[str, Any]]) -> ImportResult:
    """Write a parsed batch, inserting new dates and correcting changed ones."""
    result = ImportResult()

    if not records:
        log.warning("Nothing to import")
        return result

    stations, result.stations_created = _resolve_stations(session, records)

    # One query for every price row this batch might touch, keyed the same way
    # the unique constraint is.
    station_ids = [station.id for station in stations.values()]
    dates = {record["price_date"] for record in records if record.get("price_date")}

    existing_rows: dict[tuple[int, Any], FuelPriceHistory] = {}
    if station_ids and dates:
        for row in session.scalars(
            select(FuelPriceHistory)
            .where(FuelPriceHistory.station_id.in_(station_ids))
            .where(FuelPriceHistory.price_date.in_(dates))
        ):
            existing_rows[(row.station_id, row.price_date)] = row

    now = utcnow()
    seen: set[tuple[int, Any]] = set()

    for record in records:
        result.processed += 1

        price_date = record.get("price_date")
        station = stations.get(record.get("_fingerprint", ""))

        if station is None or price_date is None:
            result.skipped += 1
            result.errors.append(
                f"Row without a resolvable station or date: {record.get('station')!r}"
            )
            continue

        values = {column: _to_decimal(record.get(column)) for column in FUEL_COLUMNS}

        # A row where every grade is missing carries no information. Storing it
        # would put a hole in the series that looks like a station that stopped
        # selling fuel.
        if all(value is None for value in values.values()):
            result.skipped += 1
            continue

        key = (station.id, price_date)

        # The feed can list a station twice for one date — separate rows per
        # grade. Merge rather than letting the second overwrite the first, or
        # the diesel row wipes the gasoline one.
        if key in seen:
            row = existing_rows.get(key)
            if row is not None:
                for column, value in values.items():
                    if value is not None:
                        setattr(row, column, value)
            continue

        seen.add(key)
        row = existing_rows.get(key)

        if row is None:
            row = FuelPriceHistory(
                station_id=station.id,
                price_date=price_date,
                scraped_at=now,
                **values,
            )
            session.add(row)
            existing_rows[key] = row
            result.inserted += 1
            continue

        if row.differs_from(values):
            for column, value in values.items():
                setattr(row, column, value)
            row.scraped_at = now
            result.updated += 1
        else:
            result.skipped += 1

    log.info("Import complete", extra={"result": result.summary()})

    return result


# ---------------------------------------------------------------------------
# Run log
# ---------------------------------------------------------------------------


def start_run_log() -> int:
    """Open a ``running`` row and return its id.

    Committed immediately and in its own transaction, so a run that dies part
    way still leaves evidence it started.
    """
    with session_scope() as session:
        entry = ScraperLog(
            started_at=utcnow(),
            status=ScraperLog.STATUS_RUNNING,
            run_id=current_run_id(),
        )
        session.add(entry)
        session.flush()
        return entry.id


def finish_run_log(
    log_id: int,
    *,
    status: str,
    result: ImportResult | None = None,
    attempts: int = 1,
    errors: Sequence[str] = (),
) -> None:
    """Close out a run log.

    Failures here are swallowed. The scraper's exit code and stderr already
    carry the outcome, and a database that has gone away should not turn a
    partial success into a crash on the way out.
    """
    try:
        with session_scope() as session:
            entry = session.get(ScraperLog, log_id)
            if entry is None:
                log.warning("Run log %s vanished before it could be closed", log_id)
                return

            finished = utcnow()
            entry.finished_at = finished
            entry.status = status
            entry.attempts = attempts
            entry.duration_seconds = Decimal(
                str(round((finished - entry.started_at).total_seconds(), 3))
            )

            if result is not None:
                entry.records_processed = result.processed
                entry.records_inserted = result.inserted
                entry.records_updated = result.updated
                entry.records_skipped = result.skipped
                entry.stations_created = result.stations_created

            collected = [*(result.errors if result else []), *errors]
            if collected:
                # Bounded: a schema change can produce one error per row, and a
                # 40 MB TEXT write is its own outage.
                head = collected[:50]
                suffix = f"\n… and {len(collected) - 50} more" if len(collected) > 50 else ""
                entry.errors = "\n".join(head) + suffix

    except SQLAlchemyError:
        log.exception("Could not write the run log")


def last_successful_run() -> datetime | None:
    """When the scraper last completed, for health checks."""
    with session_scope() as session:
        return session.scalar(
            select(ScraperLog.finished_at)
            .where(ScraperLog.status.in_([ScraperLog.STATUS_SUCCESS, ScraperLog.STATUS_PARTIAL]))
            .order_by(ScraperLog.finished_at.desc())
            .limit(1)
        )
