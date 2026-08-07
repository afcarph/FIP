"""Columns to records.

The null-mask tests carry the most weight here. Every other failure mode in
this module is loud; that one produces a full, plausible, silently wrong
dataset.
"""

from __future__ import annotations

from datetime import date

import pytest
from conftest import double_column, make_dataset, string_column

from mapper import infer_mapping_from_labels
from parser import (
    deduplicate,
    expand_nulls,
    normalize_record,
    parse_coordinate,
    parse_date,
    parse_payloads,
    parse_price,
)


class TestExpandNulls:
    def test_it_reinserts_omitted_nulls(self) -> None:
        # Looker sends three values and a mask saying position 1 was null.
        assert expand_nulls([10, 30, 40], [1], 4) == [10, None, 30, 40]

    def test_it_handles_a_leading_null(self) -> None:
        assert expand_nulls([20, 30], [0], 3) == [None, 20, 30]

    def test_it_handles_a_trailing_null(self) -> None:
        assert expand_nulls([10, 20], [2], 3) == [10, 20, None]

    def test_it_handles_consecutive_nulls(self) -> None:
        assert expand_nulls([10], [1, 2], 3) == [10, None, None]

    def test_no_mask_leaves_the_column_alone(self) -> None:
        assert expand_nulls([1, 2, 3], [], 3) == [1, 2, 3]

    def test_an_all_null_column_becomes_all_none(self) -> None:
        assert expand_nulls([], [0, 1, 2], 3) == [None, None, None]

    def test_ignoring_the_mask_would_shift_every_later_row(self) -> None:
        # The failure this guards against, stated directly: without the mask,
        # row 1 would take row 2's price and the error propagates to the end of
        # the column.
        values, mask, rows = [58.20, 57.60], [1], 3

        assert expand_nulls(values, mask, rows) == [58.20, None, 57.60]
        assert values != expand_nulls(values, mask, rows)


class TestParsePrice:
    def test_it_reads_a_number(self) -> None:
        assert parse_price(56.85) == 56.85
        assert parse_price("56.85") == 56.85

    def test_it_strips_formatting(self) -> None:
        assert parse_price("₱1,056.85") is None  # out of range, correctly
        assert parse_price("₱56.85") == 56.85
        assert parse_price("1,056.85") is None

    def test_it_rejects_an_implausible_price(self) -> None:
        # A volume or a station count landing in a price column after a report
        # edit reordered the fields.
        assert parse_price(0) is None
        assert parse_price(-5) is None
        assert parse_price(99999) is None

    def test_it_rejects_the_feeds_ways_of_saying_nothing(self) -> None:
        for token in (None, "", "-", "N/A", "null", "no data"):
            assert parse_price(token) is None

    def test_it_rejects_nan_and_infinity(self) -> None:
        assert parse_price(float("nan")) is None
        assert parse_price(float("inf")) is None


class TestParseDate:
    @pytest.mark.parametrize(
        "value",
        ["20260806", "2026-08-06", "2026/08/06", "2026-08-06 06:00:00"],
    )
    def test_it_reads_the_formats_looker_emits(self, value: str) -> None:
        assert parse_date(value) == date(2026, 8, 6)

    def test_it_reads_epoch_seconds(self) -> None:
        assert parse_date(1_754_438_400) == date(2025, 8, 6)

    def test_it_reads_epoch_milliseconds(self) -> None:
        # The threshold has to separate the two without ambiguity, or a
        # millisecond timestamp lands 50,000 years in the future.
        assert parse_date(1_754_438_400_000) == date(2025, 8, 6)

    def test_an_unparseable_value_is_none(self) -> None:
        assert parse_date("last Tuesday") is None
        assert parse_date(None) is None


class TestParseCoordinate:
    def test_it_reads_a_philippine_coordinate(self) -> None:
        assert parse_coordinate(14.5995, is_latitude=True) == 14.5995
        assert parse_coordinate(120.9842, is_latitude=False) == 120.9842

    def test_it_rejects_zero(self) -> None:
        # (0, 0) is what an unparsed coordinate becomes. It puts a pin in the
        # Gulf of Guinea and breaks every "nearest station" query it touches.
        assert parse_coordinate(0, is_latitude=True) is None
        assert parse_coordinate(0, is_latitude=False) is None

    def test_it_rejects_a_coordinate_outside_the_country(self) -> None:
        assert parse_coordinate(51.5, is_latitude=True) is None
        assert parse_coordinate(-0.12, is_latitude=False) is None

    def test_it_rejects_swapped_coordinates(self) -> None:
        # 120.98 as a latitude is not a latitude at all.
        assert parse_coordinate(120.9842, is_latitude=True) is None


class TestNormalizeRecord:
    def test_it_builds_a_storable_record(self) -> None:
        record = normalize_record(
            {
                "station": "  Petron   EDSA ",
                "company": "Petron",
                "city": "Quezon City",
                "price_date": "20260806",
                "ron95": "62.40",
            }
        )

        assert record is not None
        assert record["station"] == "Petron EDSA"
        assert record["price_date"] == date(2026, 8, 6)
        assert record["ron95"] == 62.40

    def test_a_row_without_a_station_is_dropped(self) -> None:
        assert normalize_record({"price_date": "20260806", "ron95": 62.40}) is None

    def test_a_row_without_a_date_is_dropped(self) -> None:
        assert normalize_record({"station": "Petron EDSA", "ron95": 62.40}) is None

    def test_a_row_with_no_prices_is_dropped(self) -> None:
        # Storing it would put a hole in the series that looks like a station
        # that stopped selling fuel.
        assert normalize_record({"station": "Petron EDSA", "price_date": "20260806"}) is None

    def test_a_missing_company_falls_back_rather_than_dropping_the_row(self) -> None:
        # The DOE omits the brand for independents.
        record = normalize_record(
            {"station": "Kilometer 9 Fuels", "price_date": "20260806", "diesel": 56.0}
        )

        assert record is not None
        assert record["company"] == "Independent"


class TestParsePayloads:
    def test_it_parses_a_realistic_payload(
        self, sample_payload: dict, labels: dict[str, str], expected_first_row: dict
    ) -> None:
        mapping = infer_mapping_from_labels(labels)

        records, errors = parse_payloads([sample_payload], mapping)

        assert errors == []
        assert len(records) == 3

        first = next(record for record in records if record["station"] == "Petron EDSA")
        for key, value in expected_first_row.items():
            assert first[key] == value, f"{key} was {first[key]!r}"

    def test_nulls_land_on_the_right_rows(
        self, sample_payload: dict, labels: dict[str, str]
    ) -> None:
        # Row 1 has no RON 91 and rows 0 and 2 have no RON 97. A parser that
        # ignored the mask would give row 1 the value 57.60 — a real price, on
        # the wrong station.
        mapping = infer_mapping_from_labels(labels)

        records, _ = parse_payloads([sample_payload], mapping)
        by_station = {record["station"]: record for record in records}

        assert by_station["Shell Ortigas"]["ron91"] is None
        assert by_station["SEAOIL Pasig"]["ron91"] == 57.60
        assert by_station["Petron EDSA"]["ron97"] is None
        assert by_station["Shell Ortigas"]["ron97"] == 66.30

    def test_a_dataset_without_field_ids_is_an_error_not_a_guess(self) -> None:
        # Content can identify the dimensions but never which grade a price
        # column is. A dataset whose RON 91 and RON 95 might be swapped is
        # worse than no dataset, because it looks fine.
        payload = {
            "dataResponse": [
                {"dataSubset": [{"dataset": {"tableDataset": {"column": [double_column([56.0])]}}}]}
            ]
        }
        mapping = infer_mapping_from_labels({"qt_diesel001": "Diesel"})

        records, errors = parse_payloads([payload], mapping)

        assert records == []
        assert any("field ids" in error for error in errors)

    def test_one_bad_tile_does_not_cost_the_others(self, labels: dict) -> None:
        # A dashboard page carries several tiles, and the summary tiles
        # routinely have a shape we cannot use.
        good = make_dataset(
            ["qt_fgaojmiemc", "qt_85e4fhiemc", "qt_y7z8a9biemc"],
            [
                string_column(["20260806"]),
                string_column(["Petron EDSA"]),
                double_column([56.85]),
            ],
        )
        bad = {"dataSubset": [{"dataset": {"tableDataset": {"column": [double_column([1.0])]}}}]}

        records, errors = parse_payloads(
            [{"dataResponse": [good, bad]}], infer_mapping_from_labels(labels)
        )

        assert len(records) == 1
        assert len(errors) == 1

    def test_an_empty_dataset_yields_nothing_without_erroring(self, labels: dict) -> None:
        payload = {"dataResponse": [make_dataset([], [])]}

        records, errors = parse_payloads([payload], infer_mapping_from_labels(labels))

        assert records == []
        assert errors == []


class TestDeduplicate:
    def test_it_merges_rows_for_one_station_and_date(self) -> None:
        # The dashboard serves the same station from more than one tile, each
        # carrying a different subset of the grades.
        records = [
            {
                "station": "Petron EDSA",
                "company": "Petron",
                "city": "QC",
                "barangay": None,
                "price_date": date(2026, 8, 6),
                "ron95": 62.40,
                "diesel": None,
            },
            {
                "station": "Petron EDSA",
                "company": "Petron",
                "city": "QC",
                "barangay": None,
                "price_date": date(2026, 8, 6),
                "ron95": None,
                "diesel": 56.85,
            },
        ]

        merged = deduplicate(records)

        assert len(merged) == 1
        assert merged[0]["ron95"] == 62.40
        assert merged[0]["diesel"] == 56.85

    def test_different_dates_stay_separate(self) -> None:
        records = [
            {
                "station": "A",
                "company": "P",
                "city": "QC",
                "barangay": None,
                "price_date": date(2026, 8, 6),
                "diesel": 56.0,
            },
            {
                "station": "A",
                "company": "P",
                "city": "QC",
                "barangay": None,
                "price_date": date(2026, 8, 7),
                "diesel": 57.0,
            },
        ]

        assert len(deduplicate(records)) == 2

    def test_same_brand_in_different_cities_stays_separate(self) -> None:
        records = [
            {
                "station": "Petron",
                "company": "Petron",
                "city": "Pasig",
                "barangay": None,
                "price_date": date(2026, 8, 6),
                "diesel": 56.0,
            },
            {
                "station": "Petron",
                "company": "Petron",
                "city": "Makati",
                "barangay": None,
                "price_date": date(2026, 8, 6),
                "diesel": 57.0,
            },
        ]

        assert len(deduplicate(records)) == 2
