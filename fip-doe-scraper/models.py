"""SQLAlchemy models for the scraper's own schema.

These tables live in their own database (``fip_doe`` by default), not in the
platform's. FIP's Laravel migrations already own a ``fuel_price_history``, and
it is a different table: one row per fuel type there, one row per station-date
here. Sharing a schema would mean two owners for one name.

The grain here is deliberately the DOE's own: a station reports all its grades
on one date, so that is one row. Fanning it out to one row per fuel would make
"has this station's price changed since yesterday?" a six-row comparison for no
gain, given nothing downstream queries a single grade in isolation.
"""

from __future__ import annotations

import hashlib
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
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship

from config import FUEL_COLUMNS


class Base(DeclarativeBase):
    """Declarative base for every scraper table."""


#: BIGINT on MySQL, INTEGER on SQLite.
#:
#: SQLite only autoincrements a column declared exactly ``INTEGER PRIMARY KEY``
#: — a BIGINT primary key is not a rowid alias and inserts fail on a NOT NULL
#: id. MySQL is what production uses and wants BIGINT for a table that gains a
#: few thousand rows a day, so the type differs by dialect rather than the
#: tests running against a schema production does not have.
IdType = BigInteger().with_variant(Integer, "sqlite")


def utcnow() -> datetime:
    """The current UTC time, without a tzinfo.

    Naive on purpose. MySQL ``DATETIME`` stores no timezone, so an aware value
    written to one of these columns comes back naive — and any arithmetic
    mixing the two raises ``TypeError``. Writing aware datetimes therefore
    fails not at the write but later, at the first comparison, which for the
    run log is the duration calculation on the way out of *every* run.

    Storing naive UTC everywhere makes that impossible. Anything user-facing
    converts to Asia/Manila at the edge.
    """
    return datetime.now(tz=UTC).replace(tzinfo=None)


#: Backwards-compatible alias for the column defaults below.
_utcnow = utcnow


class FuelStation(Base):
    """A retail site as the DOE publishes it.

    The DOE does not issue station identifiers, so identity has to be derived.
    ``fingerprint`` is a hash of the fields that together name a site; it is the
    unique key because the alternative — matching on the display name alone —
    merges the four different "Petron" sites in one city into one row.
    """

    __tablename__ = "fuel_stations"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    # A stable hash of company + name + city + barangay. Indexed unique so an
    # upsert can be a single statement rather than select-then-insert, which
    # races when a retry overlaps the run it is retrying.
    fingerprint: Mapped[str] = mapped_column(String(64), nullable=False)

    company: Mapped[str] = mapped_column(String(120), nullable=False)
    name: Mapped[str | None] = mapped_column(String(200))

    # The spec's column list stops at `city`, but its own search requirements
    # name province, municipality and barangay. Storing only the city would
    # make /api/fuel/search unimplementable for three of its six geographies.
    region: Mapped[str | None] = mapped_column(String(120))
    province: Mapped[str | None] = mapped_column(String(120))
    city: Mapped[str | None] = mapped_column(String(120))
    barangay: Mapped[str | None] = mapped_column(String(120))
    address: Mapped[str | None] = mapped_column(String(400))

    # 8 decimal places is roughly a millimetre — more than the DOE publishes,
    # but truncating here would silently move stations, and the storage is free.
    latitude: Mapped[Decimal | None] = mapped_column(Numeric(10, 8))
    longitude: Mapped[Decimal | None] = mapped_column(Numeric(11, 8))

    first_seen_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=_utcnow)
    last_seen_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=_utcnow)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, server_default=func.now(), onupdate=_utcnow
    )

    prices: Mapped[list[FuelPriceHistory]] = relationship(
        back_populates="station",
        cascade="all, delete-orphan",
        passive_deletes=True,
    )

    __table_args__ = (
        UniqueConstraint("fingerprint", name="uq_fuel_stations_fingerprint"),
        Index("ix_fuel_stations_company", "company"),
        Index("ix_fuel_stations_city", "city"),
        Index("ix_fuel_stations_province", "province"),
        # Serves /api/fuel/city/{city} filtered by company, the commonest
        # search combination, without a filesort.
        Index("ix_fuel_stations_city_company", "city", "company"),
        Index("ix_fuel_stations_coords", "latitude", "longitude"),
    )

    @staticmethod
    def make_fingerprint(
        company: str | None,
        name: str | None,
        city: str | None,
        barangay: str | None,
    ) -> str:
        """Derive the identity hash.

        Case and surrounding whitespace are normalised because the DOE feed is
        inconsistent about both between weeks, and a fingerprint that changes
        with capitalisation would create a duplicate station every time an
        encoder pressed shift.
        """
        parts = [
            (company or "").strip().casefold(),
            (name or "").strip().casefold(),
            (city or "").strip().casefold(),
            (barangay or "").strip().casefold(),
        ]
        return hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<FuelStation {self.id} {self.company} {self.name!r} {self.city}>"


class FuelPriceHistory(Base):
    """One station's posted prices on one date.

    Rows are never deleted and never overwritten with equal values — see
    :func:`database.upsert_prices`. "Keep historical data forever" is the whole
    point of the table, so the only mutation permitted is correcting a price the
    DOE itself restated for a date already recorded.
    """

    __tablename__ = "fuel_price_history"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    station_id: Mapped[int] = mapped_column(
        IdType,
        ForeignKey("fuel_stations.id", ondelete="CASCADE"),
        nullable=False,
    )
    price_date: Mapped[date] = mapped_column(Date, nullable=False)

    # Nullable throughout: almost no station sells all six grades, and a zero
    # would be indistinguishable from "free" in an average.
    ron91: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))
    ron95: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))
    ron97: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))
    ron100: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))
    diesel: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))
    diesel_plus: Mapped[Decimal | None] = mapped_column(Numeric(8, 4))

    scraped_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=_utcnow)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, nullable=False, server_default=func.now(), onupdate=_utcnow
    )

    station: Mapped[FuelStation] = relationship(back_populates="prices")

    __table_args__ = (
        # The spec's unique key. Both columns are NOT NULL, which matters:
        # MySQL treats NULLs as distinct in a unique index, so a nullable
        # price_date would let the same station's date be inserted repeatedly.
        UniqueConstraint("station_id", "price_date", name="uq_price_station_date"),
        Index("ix_price_date", "price_date"),
        # Covers "this station's series", the shape every trend query takes.
        Index("ix_price_station_date", "station_id", "price_date"),
    )

    def price_map(self) -> dict[str, Decimal | None]:
        """The fuel columns as a dict, in canonical order."""
        return {column: getattr(self, column) for column in FUEL_COLUMNS}

    def differs_from(self, values: dict[str, Decimal | None]) -> bool:
        """Whether ``values`` would change any stored price.

        Compared as Decimal rather than float: 56.10 read back from MySQL is not
        equal to the float 56.1, so a float comparison reports every unchanged
        row as changed and rewrites the whole table daily.
        """
        for column in FUEL_COLUMNS:
            incoming = values.get(column)
            existing = getattr(self, column)

            if incoming is None and existing is None:
                continue
            if incoming is None or existing is None:
                return True
            if Decimal(str(incoming)) != Decimal(str(existing)):
                return True

        return False

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<FuelPriceHistory station={self.station_id} date={self.price_date}>"


class ScraperLog(Base):
    """One execution, whether or not it succeeded.

    A run that fails before it starts parsing still writes a row. A log that
    only records successes cannot answer "when did this last work?", which is
    the only question asked of it during an incident.
    """

    __tablename__ = "scraper_logs"

    STATUS_RUNNING = "running"
    STATUS_SUCCESS = "success"
    STATUS_PARTIAL = "partial"
    STATUS_FAILED = "failed"

    id: Mapped[int] = mapped_column(IdType, primary_key=True, autoincrement=True)

    started_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, default=_utcnow)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime)

    records_processed: Mapped[int] = mapped_column(IdType, nullable=False, default=0)
    records_inserted: Mapped[int] = mapped_column(IdType, nullable=False, default=0)
    # Not in the spec's column list, but "only update changed prices" is
    # unverifiable without them: inserted alone cannot distinguish a run that
    # correctly found nothing new from one that silently parsed nothing.
    records_updated: Mapped[int] = mapped_column(IdType, nullable=False, default=0)
    records_skipped: Mapped[int] = mapped_column(IdType, nullable=False, default=0)
    stations_created: Mapped[int] = mapped_column(IdType, nullable=False, default=0)

    attempts: Mapped[int] = mapped_column(SmallInteger, nullable=False, default=0)
    duration_seconds: Mapped[Decimal | None] = mapped_column(Numeric(10, 3))

    errors: Mapped[str | None] = mapped_column(Text)
    status: Mapped[str] = mapped_column(String(16), nullable=False, default=STATUS_RUNNING)
    run_id: Mapped[str | None] = mapped_column(String(32))

    __table_args__ = (
        Index("ix_scraper_logs_started_at", "started_at"),
        Index("ix_scraper_logs_status", "status"),
    )

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"<ScraperLog {self.id} {self.status} rows={self.records_processed}>"
