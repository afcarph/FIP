"""The North Luzon layout, pinned as what it actually is.

These documents are scans with an OCR text layer, not a layout variant — see
docs/investigations/north-luzon.md for the evidence. The tests here exist so
that fact cannot be quietly forgotten: if someone later writes a parser for
this layout, the assertions below are what they have to consciously change.

The fixture is one page of the real published document. A synthetic PDF would
prove nothing, because every property that matters here — the full-page image,
the base-14 fonts, the duplicated tokens — is a property of how it was made.
"""

from __future__ import annotations

from pathlib import Path

import pdfplumber
import pytest

from extractor import ExtractionError, extract
from layout_analysis import analyse_page

FIXTURE = Path(__file__).parent / "fixtures" / "north-luzon-scanned-p3.pdf"
NCR = Path(__file__).parent / "fixtures" / "ncr-price-monitoring-07282026.pdf"


@pytest.fixture(scope="module")
def page():
    with pdfplumber.open(str(FIXTURE)) as pdf:
        yield analyse_page(pdf.pages[0])


class TestItIsAScan:
    def test_the_page_is_one_image_with_no_vector_content(self, page) -> None:
        # The finding the whole recommendation rests on. Every North Luzon
        # page is a single full-page image with nothing drawn beneath it,
        # against 242 and 1,314 vector objects for the two layouts that work.
        assert page.full_page_images == 1
        assert page.vector_objects == 0
        assert page.looks_scanned is True

    def test_a_working_layout_does_not_look_scanned(self) -> None:
        # The contrast that makes the check meaningful. Without this, a
        # detector that returned True for everything would pass the test above.
        with pdfplumber.open(str(NCR)) as pdf:
            ncr = analyse_page(pdf.pages[0])

        assert ncr.looks_scanned is False

    def test_it_still_carries_a_text_layer(self, page) -> None:
        # It is not an image with no text — it is an image with OCR output
        # over it, which is worse, because the text looks usable.
        assert page.is_image_only is False
        assert page.char_count > 500

    def test_the_page_is_landscape_and_rotated(self, page) -> None:
        assert page.rotation == 270
        assert page.width > page.height


class TestTheExtractorRefusesIt:
    def test_extraction_is_rejected_rather_than_guessed_at(self) -> None:
        # The behaviour to preserve. Prices do read off these pages, but there
        # is no recoverable brand header to attribute them to, and publishing
        # brand attribution inferred from OCR geometry would be inventing it.
        with pytest.raises(ExtractionError):
            extract(FIXTURE)

    def test_the_rejection_says_what_was_wrong(self) -> None:
        with pytest.raises(ExtractionError) as raised:
            extract(FIXTURE)

        message = str(raised.value).lower()

        # A rejection nobody can act on is a rejection that gets ignored.
        assert any(word in message for word in ("region", "coverage", "threshold", "layout"))
