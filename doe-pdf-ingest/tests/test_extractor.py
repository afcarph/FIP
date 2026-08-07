"""Extraction, against the real published document.

The fixture is the DOE's own NCR report for the week of 28 July 2026, exactly
as served. Synthetic PDFs would not be worth much here: every bug this module
has had came from something the real document does and a hand-built one would
not — labels centred between rows, a stale label left over from the previous
page, baselines that straddle a bucket boundary.

The expected values below were read off the published table by hand.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from extractor import (
    ExtractedReport,
    _clean_area,
    _range_of,
    _to_price,
    extract,
    extract_with_pdfplumber,
    parse_header,
    score,
)
from settings import get_settings

FIXTURE = Path(__file__).parent / 'fixtures' / 'ncr-price-monitoring-07282026.pdf'

#: Every area the published report covers, in order.
EXPECTED_AREAS = [
    'Caloocan City', 'Quezon City', 'Manila City', 'Pasig City', 'Taguig Cty',
    'Makati City', 'Parañaque City', 'Muntinlupa City', 'Pasay City',
    'Marikina City', 'Valenzuela City', 'Navotas City',
]


@pytest.fixture(scope='module')
def report() -> ExtractedReport:
    return extract_with_pdfplumber(FIXTURE, get_settings())


class TestHeader:
    def test_it_reads_the_region(self, report: ExtractedReport) -> None:
        assert report.region == 'NCR'

    def test_it_reads_the_coverage_week(self, report: ExtractedReport) -> None:
        assert report.coverage_start.isoformat() == '2026-07-28'
        assert report.coverage_end.isoformat() == '2026-08-03'

    def test_it_reads_the_monitoring_date(self, report: ExtractedReport) -> None:
        # Distinct from the coverage week: monitoring ran 28-31 July for a week
        # that runs to 3 August.
        assert report.monitoring_date.isoformat() == '2026-07-28'

    def test_a_week_spanning_new_year_does_not_run_backwards(self) -> None:
        # The DOE prints one year on a range that crosses it. Taking it at face
        # value puts the start ~360 days after the end.
        region, start, end, _ = parse_header(
            'Price Monitoring of Liquid Fuels\nNCR\n'
            '(For the week of December 29 - January 4, 2027)'
        )

        assert region == 'NCR'
        assert start.isoformat() == '2026-12-29'
        assert end.isoformat() == '2027-01-04'

    def test_the_region_is_read_from_the_document_not_the_filename(self) -> None:
        # It has to be: the DOE publishes `region-v-bicol-8-pdf`, which carries
        # no date, and `ncr-price-monitoring-11112025` without the `-pdf` its
        # siblings have.
        region, _, _, _ = parse_header(
            'Price Monitoring of Liquid Fuels\nRegion IV-A (CALABARZON)\n'
            '(For the week of July 28 - August 3, 2026)'
        )

        assert region.startswith('Region IV-A')


class TestAreas:
    def test_it_finds_every_area(self, report: ExtractedReport) -> None:
        assert report.areas == EXPECTED_AREAS

    def test_no_block_is_skipped(self, report: ExtractedReport) -> None:
        # A warning here means a block's prices were dropped. Silent data loss
        # is the failure this module exists to avoid.
        assert report.warnings == []

    def test_a_stale_label_does_not_steal_the_next_areas_prices(
        self, report: ExtractedReport
    ) -> None:
        # Regression. Page two of this document carries two labels two points
        # apart: a leftover "Caloocan City" from page one sitting on the real
        # "Muntinlupa City". Taking the topmost filed Muntinlupa's whole table
        # under Caloocan, and Caloocan's own figures never appeared.
        caloocan = {
            price.brand: (price.min_price, price.max_price)
            for price in report.prices
            if price.area == 'Caloocan City' and price.product == 'RON 95'
        }
        muntinlupa = {
            price.brand: (price.min_price, price.max_price)
            for price in report.prices
            if price.area == 'Muntinlupa City' and price.product == 'RON 95'
        }

        assert caloocan['Petron'] == (79.50, 87.50)
        assert muntinlupa['Petron'] == (81.80, 88.60)

    def test_an_interleaved_label_is_rejected(self) -> None:
        # Two overlapping labels come out of the text layer character by
        # character. Guessing at one would file a city's prices under a city
        # that does not exist.
        assert _clean_area('MCuanlotioncluapna C Citiyty') is None

    def test_real_area_names_survive(self) -> None:
        for name in ('Quezon City', 'Parañaque City', 'Las Piñas', 'Taguig Cty'):
            assert _clean_area(name) == name

    def test_page_furniture_is_not_an_area(self) -> None:
        assert _clean_area('Date of Monitoring') is None
        assert _clean_area('AREA') is None


class TestBrandAttribution:
    def test_prices_land_on_the_brands_that_published_them(
        self, report: ExtractedReport
    ) -> None:
        # The whole reason extraction is coordinate-based. Caloocan's RON 95
        # row prints six pairs, but they belong to Petron, Shell, Caltex,
        # Flying V, Unioil and Independent — Phoenix, Total, Seaoil and PTT are
        # blank. Reading the numbers left to right would hand Phoenix the pair
        # that belongs to Flying V and shift everything after it.
        row = {
            price.brand: (price.min_price, price.max_price)
            for price in report.prices
            if price.area == 'Caloocan City' and price.product == 'RON 95'
        }

        assert row['Petron'] == (79.50, 87.50)
        assert row['Shell'] == (87.60, 93.00)
        assert row['Caltex'] == (93.90, 93.90)
        assert row['Flying V'] == (78.30, 81.90)
        assert row['Unioil'] == (87.10, 87.10)
        assert row['Independent'] == (71.80, 82.90)

    def test_a_brand_that_did_not_publish_is_absent(self, report: ExtractedReport) -> None:
        brands = {
            price.brand
            for price in report.prices
            if price.area == 'Caloocan City' and price.product == 'RON 95'
        }

        assert 'Phoenix' not in brands
        assert 'Ptt' not in brands

    def test_every_brand_column_is_recognised(self, report: ExtractedReport) -> None:
        # "FLYING V" is two words in the header and must not merge into its
        # neighbour, nor split into a phantom column.
        assert report.brands == [
            'PETRON', 'SHELL', 'CALTEX', 'PHOENIX', 'TOTAL',
            'FLYING V', 'UNIOIL', 'SEAOIL', 'PTT', 'INDEPENDENT',
        ]

    def test_the_area_overall_range_is_captured(self, report: ExtractedReport) -> None:
        overall = next(
            price for price in report.prices
            if price.area == 'Caloocan City'
            and price.product == 'RON 95'
            and price.is_overall
        )

        assert (overall.min_price, overall.max_price) == (71.80, 93.90)

    def test_the_common_price_is_captured_where_published(
        self, report: ExtractedReport
    ) -> None:
        overall = next(
            price for price in report.prices
            if price.area == 'Caloocan City'
            and price.product == 'RON 91'
            and price.is_overall
        )

        assert overall.common_price == 85.60


class TestRowIntegrity:
    def test_every_product_row_is_read(self, report: ExtractedReport) -> None:
        # Regression. Rows were bucketed by `int(top // 3)`, which splits a row
        # whose words straddle a multiple of three — Caloocan's RON 95 sits at
        # y=156.81, half a point from the boundary, and lost its prices while
        # reporting no error at all.
        products = {
            price.product for price in report.prices if price.area == 'Caloocan City'
        }

        assert {'RON 97', 'RON 95', 'RON 91', 'DIESEL', 'DIESEL PLUS', 'KEROSENE'} <= products

    def test_diesel_plus_is_not_read_as_diesel(self, report: ExtractedReport) -> None:
        codes = {
            price.product: price.fuel_code
            for price in report.prices
            if price.area == 'Caloocan City'
        }

        assert codes['DIESEL'] == 'diesel'
        assert codes['DIESEL PLUS'] == 'diesel_premium'

    def test_it_maps_products_to_platform_fuel_codes(self, report: ExtractedReport) -> None:
        # The values are fuel_types.code, so the import lands in the platform's
        # own vocabulary rather than a parallel one.
        codes = {price.fuel_code for price in report.prices}

        assert 'gasoline_ron95' in codes
        assert 'diesel' in codes

    def test_a_product_the_area_does_not_sell_yields_nothing(
        self, report: ExtractedReport
    ) -> None:
        # Caloocan publishes no RON 100 — the cell reads "#N/A". A zero here
        # would drag every minimum and average down.
        rows = [
            price for price in report.prices
            if price.area == 'Caloocan City' and price.product == 'RON 100'
        ]

        assert rows == []


class TestValues:
    def test_it_parses_a_price(self) -> None:
        assert _to_price('79.50') == 79.50

    def test_the_feeds_null_tokens_are_not_prices(self) -> None:
        for token in ('#N/A', 'N/A', 'NONE', '-', ''):
            assert _to_price(token) is None

    def test_zero_is_not_a_price(self) -> None:
        # The DOE prints 0.00 beside a real range as another way of writing
        # "no data". Storing it would put every minimum at zero.
        assert _to_price('0.00') is None

    def test_it_parses_an_overall_range(self) -> None:
        assert _range_of('71.80 - 93.90') == (71.80, 93.90)

    def test_an_empty_range_is_not_a_range(self) -> None:
        assert _range_of('#N/A') == (None, None)


class TestQualityScore:
    def test_a_good_extraction_scores_high(self, report: ExtractedReport) -> None:
        assert report.quality >= 0.9

    def test_an_empty_extraction_scores_zero(self) -> None:
        assert score(ExtractedReport(None, None, None, None)) == 0.0

    def test_a_report_without_a_region_scores_lower(self, report: ExtractedReport) -> None:
        # Without a region the report cannot be filed, so it must not score as
        # well as one that has it.
        anonymous = ExtractedReport(
            None, report.coverage_start, report.coverage_end, None,
            prices=report.prices,
        )

        assert score(anonymous) < report.quality


class TestExtractorSelection:
    def test_it_picks_an_extraction_that_clears_the_bar(self) -> None:
        result = extract(FIXTURE)

        assert result.quality >= get_settings().min_extraction_quality
        assert result.region == 'NCR'
        assert len(result.areas) == 12
