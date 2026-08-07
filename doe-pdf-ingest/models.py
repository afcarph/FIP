"""SQLAlchemy models for the ingestion tables.

These live in the platform's own database and are the *only* tables this
service writes. Three things they deliberately are not:

  * They are not a station directory. The DOE's price monitoring PDFs contain
    no station-level data at all — no names, no addresses, no coordinates. The
    published grain is area by product by brand, and inventing a `fuel_stations`
    table to hold nothing would be worse than not having one. The platform's
    own `gas_stations` remains the station directory.
  * They are not a second copy of `station_prices`. Nothing here is a
    per-station pump price, so nothing here competes with the platform's
    existing price model.
  * They are not the platform's `price_advisories`, which record weekly
    *changes* per region. These are published *levels* per area and brand,
    which FIP has never had anywhere.

The schema is created by a Laravel migration, not by this module — the platform
owns its own database structure. These classes describe it so the ingest can
write, and `verify_schema` checks the two agree.
"""

from __future__ import annotations

from datetime import UTC, date, datetime
from decimal import Decimal

from sqlalchemy import (
    BigInteger,
    Date,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    """Declarative base for the ingestion tables."""


#: BIGINT on MySQL, INTEGER on SQLite. SQLite only autoincrements a column
#: declared exactly `INTEGER PRIMARY KEY`, so tests against it would otherwise
#: fail on a NOT NULL id while production wants BIGINT.
IdType = BigInteger().with_variant(Integer, "sqlite")


def utcnow() -> datetime:
    """Current UTC time, naive.

    MySQL DATETIME stores no timezone, so an aware value written to one of
    these columns reads back naive and any arithmetic mixing the two raises.
    That failure lands at the first comparison, not the write.
    """
    return datetime.now(tz=UTC).replace(tzinfo=None)


class FuelReport(Base):
    """One published DOE price monitoring PDF.

    Identity is the checksum of the PDF bytes — the same document is the same
    report whatever URL or filename it arrived under, and the DOE's filenames
    are not consistent enough to key on.
    """

    __tablename__ = "fuel_reports"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    #: As printed in the document, e.g. "NCR", "Region IV-A (CALABARZON)".
    #: Read from the PDF header, never from the filename.
    region: Mapped[str] = mapped_column(String(120), nullable=False)

    #: When the DOE published it. Distinct from the coverage week and from the
    #: monitoring date, all three of which the document states separately.
    publication_date: Mapped[date | None] = mapped_column(Date)
    coverage_start: Mapped[date] = mapped_column(Date, nullable=False)
    coverage_end: Mapped[date] = mapped_column(Date, nullable=False)
    monitoring_date: Mapped[date | None] = mapped_column(Date)

    source_url: Mapped[str | None] = mapped_column(String(500))
    pdf_filename: Mapped[str] = mapped_column(String(255), nullable=False)
    #: Where the original is kept, so an extraction can be re-run.
    pdf_path: Mapped[str | None] = mapped_column(String(500))
    checksum: Mapped[str] = mapped_column(String(64), nullable=False)

    #: Which extractor won, and what it scored. Kept because a report that
    #: imported at 0.6 is worth looking at before one that imported at 0.98.
    extractor: Mapped[str | None] = mapped_column(String(40))
    quality: Mapped[Decimal | None] = mapped_column(Numeric(5, 4))

    areas_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    rows_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)

    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, default=utcnow, onupdate=utcnow
    )

    prices: Mapped[list[FuelPrice]] = relationship(
        back_populates="report", cascade="all, delete-orphan", passive_deletes=True
    )

    __table_args__ = (
        # The duplicate-report rule. Byte-identical means already imported.
        UniqueConstraint("checksum", name="uq_fuel_reports_checksum"),
        # And the semantic one: a region publishes one report per week. This
        # catches a re-issued PDF whose bytes differ — a correction — which the
        # checksum alone would let in as a second report for the same week.
        UniqueConstraint("region", "coverage_start", name="uq_fuel_reports_region_week"),
        Index("ix_fuel_reports_coverage", "coverage_start", "coverage_end"),
        Index("ix_fuel_reports_region", "region"),
    )

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<FuelReport {self.id} {self.region} {self.coverage_start}>"


class FuelPrice(Base):
    """One area by product by brand cell from a report.

    A row with `brand` set is that brand's published range in that area. A row
    with `brand` NULL is the area's overall range and common price, which the
    DOE prints as its own column rather than deriving — so it is stored as
    published rather than recomputed.
    """

    __tablename__ = "fuel_prices"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    report_id: Mapped[int] = mapped_column(
        IdType, ForeignKey("fuel_reports.id", ondelete="CASCADE"), nullable=False
    )

    #: City or municipality as the DOE prints it.
    area: Mapped[str] = mapped_column(String(160), nullable=False)

    #: The DOE's own product label, kept verbatim for traceability.
    product: Mapped[str] = mapped_column(String(40), nullable=False)
    #: The platform's `fuel_types.code`. Resolved at extraction so this lands
    #: in FIP's vocabulary rather than a parallel one. Nullable because the DOE
    #: publishes RON 100, which the platform has no fuel type for — carried
    #: rather than silently mapped onto RON 97.
    fuel_code: Mapped[str | None] = mapped_column(String(40))

    #: NULL for the area's overall row.
    brand: Mapped[str | None] = mapped_column(String(80))

    min_price: Mapped[Decimal | None] = mapped_column(Numeric(8, 2))
    max_price: Mapped[Decimal | None] = mapped_column(Numeric(8, 2))
    #: Only ever published on the overall row.
    common_price: Mapped[Decimal | None] = mapped_column(Numeric(8, 2))

    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=utcnow)

    report: Mapped[FuelReport] = relationship(back_populates="prices")

    __table_args__ = (
        # One range per brand per product per area per report. Re-importing a
        # report updates these rows rather than adding a second set.
        #
        # `brand` is nullable and MySQL treats NULLs as distinct in a unique
        # index, so the overall row is *not* protected by this constraint —
        # storage.py deletes a report's rows before rewriting them, which
        # covers it without a sentinel brand value that every query would then
        # have to filter out.
        UniqueConstraint("report_id", "area", "product", "brand", name="uq_fuel_prices_cell"),
        Index("ix_fuel_prices_report", "report_id"),
        Index("ix_fuel_prices_area", "area"),
        Index("ix_fuel_prices_brand", "brand"),
        Index("ix_fuel_prices_fuel", "fuel_code"),
    )

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<FuelPrice {self.area} {self.product} {self.brand}>"


class ImportRun(Base):
    """One execution of the ingest, whether or not it found anything.

    A run that discovers no new PDF is a success and still writes a row. Without
    it the only evidence of a healthy Sunday is an absence, which is
    indistinguishable from the scheduler having died.
    """

    __tablename__ = "doe_import_runs"

    STATUS_RUNNING = "running"
    STATUS_SUCCESS = "success"
    #: Some reports imported and some failed.
    STATUS_PARTIAL = "partial"
    STATUS_FAILED = "failed"
    #: Ran cleanly, nothing new published.
    STATUS_NO_CHANGES = "no_changes"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    started_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=utcnow)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime)
    duration_seconds: Mapped[Decimal | None] = mapped_column(Numeric(10, 3))

    pdfs_discovered: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    pdfs_downloaded: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    reports_imported: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    reports_skipped: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    records_imported: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    records_updated: Mapped[int] = mapped_column(Integer, nullable=False, default=0)

    status: Mapped[str] = mapped_column(String(16), nullable=False, default=STATUS_RUNNING)
    errors: Mapped[str | None] = mapped_column(Text)
    run_id: Mapped[str | None] = mapped_column(String(32))

    __table_args__ = (
        Index("ix_doe_import_runs_started", "started_at"),
        Index("ix_doe_import_runs_status", "status"),
    )

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<ImportRun {self.id} {self.status}>"
