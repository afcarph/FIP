"""Field mapping: generated ids to published labels to our columns."""

from __future__ import annotations

from mapper import (
    build_mapping,
    classify_label,
    extract_labels,
    infer_mapping_from_labels,
)


class TestClassifyLabel:
    def test_it_maps_the_published_fuel_labels(self) -> None:
        assert classify_label("RON 91") == "ron91"
        assert classify_label("RON 95") == "ron95"
        assert classify_label("RON 97") == "ron97"
        assert classify_label("RON 100") == "ron100"
        assert classify_label("Diesel") == "diesel"

    def test_diesel_plus_is_not_swallowed_by_diesel(self) -> None:
        # "Diesel Plus" contains "Diesel". Ordering the patterns wrongly puts a
        # premium product's price into the regular diesel column, which reads as
        # a plausible price rather than as a bug.
        assert classify_label("Diesel Plus") == "diesel_plus"
        assert classify_label("Diesel+") == "diesel_plus"
        assert classify_label("DIESEL PREMIUM") == "diesel_plus"

    def test_the_qualifier_may_come_first(self) -> None:
        assert classify_label("Euro 5 Diesel") == "diesel_plus"
        assert classify_label("Premium Diesel") == "diesel_plus"

    def test_ron_100_is_not_read_as_ron_10(self) -> None:
        assert classify_label("RON 100") == "ron100"

    def test_it_maps_the_dimensions(self) -> None:
        assert classify_label("Gas Station") == "station"
        assert classify_label("Company") == "company"
        assert classify_label("City/Municipality") == "city"
        assert classify_label("Municipality") == "city"
        assert classify_label("Barangay") == "barangay"
        assert classify_label("Province") == "province"
        assert classify_label("Timestamp") == "price_date"
        assert classify_label("Latitude") == "latitude"
        assert classify_label("Longitude") == "longitude"

    def test_it_tolerates_the_report_authors_formatting(self) -> None:
        assert classify_label("  gas_station  ") == "station"
        assert classify_label("RON_95") == "ron95"
        assert classify_label("ron95") == "ron95"

    def test_an_unrelated_label_maps_to_nothing(self) -> None:
        assert classify_label("Volume Sold") is None
        assert classify_label("") is None


class TestExtractLabels:
    def test_it_finds_fields_wherever_they_are_nested(self) -> None:
        # The nesting differs between report versions, so the walk must not
        # depend on a fixed path.
        payload = {
            "datasourceSchema": {
                "dataset": {
                    "fields": [
                        {"name": "qt_fgaojmiemc", "label": "Timestamp"},
                        {"name": "qt_85e4fhiemc", "label": "Gas Station"},
                    ]
                }
            }
        }

        assert extract_labels(payload) == {
            "qt_fgaojmiemc": "Timestamp",
            "qt_85e4fhiemc": "Gas Station",
        }

    def test_it_reads_a_flat_field_list(self) -> None:
        payload = {"fields": [{"id": "qt_abcdefgh", "displayName": "Diesel"}]}

        assert extract_labels(payload) == {"qt_abcdefgh": "Diesel"}

    def test_a_field_whose_label_is_its_own_id_is_ignored(self) -> None:
        # Nothing is learned from it, and storing it would make the id look
        # like a resolved label in the logs.
        payload = {"fields": [{"name": "qt_abcdefgh", "label": "qt_abcdefgh"}]}

        assert extract_labels(payload) == {}

    def test_the_first_definition_wins(self) -> None:
        # getReport repeats a field per tile, sometimes with a tile-local alias.
        payload = {
            "fields": [
                {"name": "qt_abcdefgh", "label": "Diesel"},
                {"name": "qt_abcdefgh", "label": "Diesel (tile 3)"},
            ]
        }

        assert extract_labels(payload) == {"qt_abcdefgh": "Diesel"}


class TestBuildMapping:
    def test_it_resolves_ids_to_columns(self, labels: dict[str, str]) -> None:
        payload = {
            "fields": [{"name": field_id, "label": label} for field_id, label in labels.items()]
        }

        mapping = build_mapping([payload])

        assert mapping.column_for("qt_85e4fhiemc") == "station"
        assert mapping.column_for("qt_p7q8r9siemc") == "ron95"
        assert mapping.column_for("qt_b1c2d3eiemc") == "diesel_plus"
        assert mapping.is_usable

    def test_it_merges_several_schema_responses(self) -> None:
        # A dashboard with a separate data source for its map tile returns two.
        first = {"fields": [{"name": "qt_station01", "label": "Gas Station"}]}
        second = {"fields": [{"name": "qt_diesel001", "label": "Diesel"}]}

        mapping = build_mapping([first, second])

        assert mapping.column_for("qt_station01") == "station"
        assert mapping.column_for("qt_diesel001") == "diesel"

    def test_a_second_id_claiming_a_taken_column_is_rejected(self) -> None:
        # Otherwise dict ordering decides which field the prices come from.
        payload = {
            "fields": [
                {"name": "qt_diesel001", "label": "Diesel"},
                {"name": "qt_diesel002", "label": "Diesel Price"},
            ]
        }

        mapping = build_mapping([payload])

        assert mapping.column_for("qt_diesel001") == "diesel"
        assert mapping.column_for("qt_diesel002") is None
        assert any("duplicate" in entry for entry in mapping.unmapped)

    def test_a_mapping_without_prices_is_not_usable(self) -> None:
        # It would import rows of nothing, and report success doing it.
        mapping = infer_mapping_from_labels({"qt_station01": "Gas Station"})

        assert not mapping.is_usable

    def test_a_mapping_without_a_station_is_not_usable(self) -> None:
        mapping = infer_mapping_from_labels({"qt_diesel001": "Diesel"})

        assert not mapping.is_usable

    def test_unmapped_labels_are_recorded_rather_than_dropped(self) -> None:
        mapping = infer_mapping_from_labels(
            {"qt_station01": "Gas Station", "qt_volume001": "Volume Sold"}
        )

        assert "Volume Sold" in mapping.unmapped
