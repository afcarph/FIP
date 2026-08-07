"""The layout tool, checked against documents whose nature is already known.

The tool's job is to be trusted when a layout is unfamiliar, which means its
own findings have to be right on the layouts that are familiar. Both bugs it
guards against here were shipped by the extractor first.
"""

from __future__ import annotations

import json
from pathlib import Path

import pdfplumber
import pytest

from layout_analysis import _row_bands, analyse_page

FIXTURES = Path(__file__).parent / "fixtures"
NCR = FIXTURES / "ncr-price-monitoring-07282026.pdf"
VISAYAS = FIXTURES / "vfo-lf-price-monitoring-112525.pdf"


@pytest.fixture(scope="module")
def ncr_page():
    with pdfplumber.open(str(NCR)) as pdf:
        yield analyse_page(pdf.pages[0])


class TestRowBands:
    def test_rows_are_not_chained_into_one_band(self, ncr_page) -> None:
        # The first draft compared each word against the band's bottom edge,
        # which has already grown by a line height — so the next row sat flush
        # against it and merged. A 725-word table reported two rows.
        assert len(ncr_page.rows) > 20

    def test_a_baseline_straddling_a_boundary_does_not_split_a_row(self) -> None:
        # `int(top // n)` bucketing put these three in two rows.
        words = [
            {"text": "a", "x0": 10, "x1": 20, "top": 99.6, "bottom": 107.6},
            {"text": "b", "x0": 30, "x1": 40, "top": 100.1, "bottom": 108.1},
            {"text": "c", "x0": 50, "x1": 60, "top": 100.4, "bottom": 108.4},
        ]

        assert len(_row_bands(words, line_height=8)) == 1

    def test_genuinely_separate_rows_stay_separate(self) -> None:
        words = [
            {"text": "a", "x0": 10, "x1": 20, "top": 100.0, "bottom": 108.0},
            {"text": "b", "x0": 10, "x1": 20, "top": 116.0, "bottom": 124.0},
        ]

        assert len(_row_bands(words, line_height=8)) == 2

    def test_no_words_yields_no_bands(self) -> None:
        assert _row_bands([], line_height=8) == []


class TestScanDetection:
    def test_a_vector_page_is_not_reported_as_scanned(self, ncr_page) -> None:
        # The distinction the North Luzon finding rests on. A false positive
        # here would excuse a layout that is genuinely parseable.
        assert ncr_page.looks_scanned is False
        assert ncr_page.full_page_images == 0
        assert ncr_page.vector_objects > 0

    def test_the_visayas_layout_is_not_reported_as_scanned(self) -> None:
        with pdfplumber.open(str(VISAYAS)) as pdf:
            analysis = analyse_page(pdf.pages[0])

        assert analysis.looks_scanned is False

    def test_a_page_with_text_is_not_image_only(self, ncr_page) -> None:
        assert ncr_page.is_image_only is False
        assert ncr_page.char_count > 0


class TestItDescribesRatherThanDecides:
    def test_every_word_is_placed_in_a_row_and_ordered(self, ncr_page) -> None:
        assert ncr_page.words
        assert all(word.row_band >= 0 for word in ncr_page.words)
        assert [word.reading_order for word in ncr_page.words] == list(
            range(len(ncr_page.words))
        )

    def test_column_candidates_carry_the_gap_that_produced_them(self, ncr_page) -> None:
        # A candidate with no stated evidence is an assertion, and this tool
        # exists so that assertions about layout can be checked.
        assert ncr_page.columns
        assert all(column.gap_before >= 0 for column in ncr_page.columns)
        assert any(column.word_count > 0 for column in ncr_page.columns)

    def test_the_json_round_trips(self, ncr_page) -> None:
        payload = json.loads(json.dumps(ncr_page.to_json()))

        assert payload["word_count"] == ncr_page.word_count
        assert len(payload["words"]) == len(ncr_page.words)
        assert "looks_scanned" in payload
