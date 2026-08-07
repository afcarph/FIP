"""The import rules: identity, idempotence, change detection, no deletion."""

from __future__ import annotations

from datetime import date
from decimal import Decimal

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from database import upsert_prices
from models import FuelPriceHistory, FuelStation


def record(**overrides: object) -> dict:
    """A parsed record, with sensible defaults."""
    base = {
        "station": "Petron EDSA",
        "company": "Petron",
        "city": "Quezon City",
        "province": "Metro Manila",
        "barangay": "Bagumbayan",
        "address": "123 EDSA",
        "region": "NCR",
        "latitude": 14.5995,
        "longitude": 120.9842,
        "price_date": date(2026, 8, 6),
        "ron91": 58.20,
        "ron95": 62.40,
        "ron97": None,
        "ron100": None,
        "diesel": 56.85,
        "diesel_plus": None,
    }
    base.update(overrides)
    return base


class TestStationIdentity:
    def test_a_new_station_is_created(self, session: Session) -> None:
        result = upsert_prices(session, [record()])

        assert result.stations_created == 1
        assert session.scalar(select(func.count()).select_from(FuelStation)) == 1

    def test_the_same_station_is_not_created_twice(self, session: Session) -> None:
        upsert_prices(session, [record()])
        session.commit()

        result = upsert_prices(session, [record(price_date=date(2026, 8, 7))])

        assert result.stations_created == 0
        assert session.scalar(select(func.count()).select_from(FuelStation)) == 1

    def test_identity_survives_a_change_of_capitalisation(self, session: Session) -> None:
        # The feed is inconsistent about case between weeks. A fingerprint that
        # changed with it would create a duplicate station every time.
        upsert_prices(session, [record()])
        session.commit()

        upsert_prices(session, [record(station="PETRON EDSA", company="petron")])

        assert session.scalar(select(func.count()).select_from(FuelStation)) == 1

    def test_two_sites_of_one_brand_in_one_city_stay_separate(self, session: Session) -> None:
        # Matching on the display name alone merges them into one row.
        upsert_prices(session, [record(station="Petron EDSA", barangay="Bagumbayan")])
        upsert_prices(session, [record(station="Petron Kamias", barangay="Kamias")])

        assert session.scalar(select(func.count()).select_from(FuelStation)) == 2

    def test_a_missing_field_is_backfilled_later(self, session: Session) -> None:
        upsert_prices(session, [record(latitude=None, longitude=None, address=None)])
        session.commit()

        upsert_prices(session, [record(price_date=date(2026, 8, 7))])
        session.commit()

        station = session.scalars(select(FuelStation)).one()
        assert station.latitude == Decimal("14.59950000")
        assert station.address == "123 EDSA"

    def test_a_feed_that_drops_a_field_does_not_blank_it(self, session: Session) -> None:
        upsert_prices(session, [record()])
        session.commit()

        upsert_prices(session, [record(price_date=date(2026, 8, 7), address=None, latitude=None)])
        session.commit()

        station = session.scalars(select(FuelStation)).one()
        assert station.address == "123 EDSA"
        assert station.latitude is not None


class TestPriceImport:
    def test_a_new_date_is_inserted(self, session: Session) -> None:
        result = upsert_prices(session, [record()])

        assert result.inserted == 1
        assert result.updated == 0

    def test_re_running_the_same_day_writes_nothing(self, session: Session) -> None:
        # Idempotence. Rewriting equal values daily would make updated_at
        # useless and produce a binlog the size of the table for no information.
        upsert_prices(session, [record()])
        session.commit()

        result = upsert_prices(session, [record()])

        assert result.inserted == 0
        assert result.updated == 0
        assert result.skipped == 1

    def test_a_changed_price_is_corrected(self, session: Session) -> None:
        upsert_prices(session, [record()])
        session.commit()

        result = upsert_prices(session, [record(ron95=63.10)])
        session.commit()

        assert result.updated == 1
        row = session.scalars(select(FuelPriceHistory)).one()
        assert row.ron95 == Decimal("63.1000")

    def test_a_grade_appearing_for_the_first_time_counts_as_a_change(
        self, session: Session
    ) -> None:
        upsert_prices(session, [record(ron97=None)])
        session.commit()

        result = upsert_prices(session, [record(ron97=66.30)])

        assert result.updated == 1

    def test_history_accumulates_and_is_never_replaced(self, session: Session) -> None:
        # "Keep historical data forever" is the whole point of the table.
        for day in (5, 6, 7):
            upsert_prices(session, [record(price_date=date(2026, 8, day))])
            session.commit()

        rows = session.scalars(select(FuelPriceHistory).order_by(FuelPriceHistory.price_date)).all()

        assert [row.price_date for row in rows] == [
            date(2026, 8, 5),
            date(2026, 8, 6),
            date(2026, 8, 7),
        ]

    def test_a_row_with_no_prices_is_skipped(self, session: Session) -> None:
        result = upsert_prices(
            session,
            [
                record(
                    ron91=None,
                    ron95=None,
                    ron97=None,
                    ron100=None,
                    diesel=None,
                    diesel_plus=None,
                )
            ],
        )

        assert result.inserted == 0
        assert result.skipped == 1

    def test_two_rows_for_one_station_and_date_are_merged(self, session: Session) -> None:
        # The feed lists a station once per grade in some tiles. Letting the
        # second row overwrite the first loses the gasoline prices.
        result = upsert_prices(
            session,
            [
                record(ron95=62.40, diesel=None),
                record(ron95=None, diesel=56.85),
            ],
        )
        session.commit()

        assert result.inserted == 1
        row = session.scalars(select(FuelPriceHistory)).one()
        assert row.ron95 == Decimal("62.4000")
        assert row.diesel == Decimal("56.8500")

    def test_an_empty_batch_is_harmless(self, session: Session) -> None:
        result = upsert_prices(session, [])

        assert result.processed == 0
        assert result.errors == []


class TestChangeDetection:
    def test_a_decimal_read_back_from_the_database_compares_equal(self, session: Session) -> None:
        # 56.10 read back as Decimal is not equal to the float 56.1. Comparing
        # as floats reports every unchanged row as changed and rewrites the
        # whole table daily.
        upsert_prices(session, [record(ron95=56.10)])
        session.commit()

        result = upsert_prices(session, [record(ron95=56.10)])

        assert result.updated == 0
        assert result.skipped == 1

    def test_differs_from_detects_a_grade_disappearing(self, session: Session) -> None:
        upsert_prices(session, [record(diesel=56.85)])
        session.commit()

        row = session.scalars(select(FuelPriceHistory)).one()

        assert row.differs_from({"diesel": None})
        assert not row.differs_from(row.price_map())


class TestImportResult:
    def test_the_summary_reports_every_count(self, session: Session) -> None:
        result = upsert_prices(session, [record()])

        summary = result.summary()
        for token in (
            "processed=",
            "inserted=",
            "updated=",
            "unchanged=",
            "new_stations=",
        ):
            assert token in summary

    def test_wrote_anything_is_false_for_an_unchanged_run(self, session: Session) -> None:
        upsert_prices(session, [record()])
        session.commit()

        assert not upsert_prices(session, [record()]).wrote_anything
