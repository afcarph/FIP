"""The GraphQL discovery provider.

No network: the API is stubbed. What these cover is the reasoning that sits
between the response and a candidate — which titles count, how pages are
walked, and when walking stops.
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from typing import Any

import pytest

from discovery import (
    DiscoveredPdf,
    DiscoveryError,
    DiscoveryProvider,
    GraphQlDiscoveryProvider,
    ManualSeedProvider,
    classify_title,
    date_from_title,
)
from settings import get_settings


class _FakeResponse:
    def __init__(self, payload: dict, status: int = 200) -> None:
        self._payload = payload
        self.status_code = status

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")

    def json(self) -> dict:
        return self._payload


class _FakeSession:
    """Serves canned pages and records what was asked for."""

    def __init__(self, pages: list[list[dict]]) -> None:
        self.pages = pages
        self.requests: list[dict] = []
        self.headers: dict[str, str] = {}

    def post(self, url: str, json: dict, timeout: int) -> _FakeResponse:
        self.requests.append(json)
        index = json["variables"]["page"] - 1
        items = self.pages[index] if index < len(self.pages) else []

        return _FakeResponse({"data": {"documents": {"totalCount": 99, "items": items}}})


def _doc(title: str, days_ago: int = 0, url: str | None = None) -> dict:
    when = datetime.now(tz=UTC) - timedelta(days=days_ago)

    return {
        "id": abs(hash(title)) % 10**6,
        "title": title,
        "contentUrl": url or f"/documents/20119/0/{title.replace(' ', '%20')}",
        "dateModified": when.strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def _provider(
    pages: list[list[dict]], **overrides: Any
) -> tuple[GraphQlDiscoveryProvider, _FakeSession]:
    settings = get_settings().model_copy(update=overrides)
    session = _FakeSession(pages)

    return GraphQlDiscoveryProvider(settings, session), session  # type: ignore[arg-type]


class TestTitleClassification:
    def test_it_recognises_the_published_report_titles(self) -> None:
        # The real titles, as the CMS stores them.
        for title in (
            "NCR Price Monitoring 07282026.pdf",
            "VFO PRICE MONITORING 080426_with LGU and Field.pdf",
            "North Luzon Liquid Fuels Price Monitoring Report for 21-27 July 2026.pdf",
        ):
            assert classify_title(title)[0], title

    def test_it_ignores_everything_else_in_the_library(self) -> None:
        # The library holds 14,898 documents and most are not price reports.
        for title in (
            "RFQ - 15 VFO Security Services 2024",
            "DOE Organizational Chart as of 01 Aug 2026 (1).pdf",
            "do2021-09-0013",
            "AUGUST 2026.pdf",
        ):
            assert not classify_title(title)[0], title

    def test_it_hints_at_the_region(self) -> None:
        assert classify_title("NCR Price Monitoring 07282026.pdf")[1] == "NCR"
        assert classify_title("VFO PRICE MONITORING 080426.pdf")[1] == "Visayas"

    def test_the_hint_is_only_a_hint(self) -> None:
        # A title with no recognisable region is still a report. The region
        # that gets stored is the one printed inside the PDF.
        relevant, region = classify_title("Liquid Fuels Price Monitoring 080426.pdf")

        assert relevant
        assert region is None

    def test_it_reads_a_date_from_a_title(self) -> None:
        assert date_from_title("NCR Price Monitoring 07282026.pdf").isoformat() == "2026-07-28"
        assert date_from_title("VFO PRICE MONITORING 080426.pdf").isoformat() == "2026-08-04"

    def test_a_title_with_no_date_yields_none(self) -> None:
        assert date_from_title("Region V Bicol Price Monitoring.pdf") is None


class TestResponseParsing:
    def test_it_builds_candidates_from_the_api_response(self) -> None:
        provider, _ = _provider([[_doc("NCR Price Monitoring 07282026.pdf")]])

        found = provider.discover()

        assert len(found) == 1
        assert found[0].title.startswith("NCR Price Monitoring")
        assert found[0].region == "NCR"
        assert found[0].content_url.startswith("https://")
        assert found[0].publication_date.isoformat() == "2026-07-28"

    def test_the_filename_comes_from_the_url_not_the_title(self) -> None:
        # Titles carry spaces and two reports can share one.
        provider, _ = _provider([[_doc("NCR Price Monitoring 07282026.pdf")]])

        assert provider.discover()[0].filename.endswith(".pdf")
        assert " " not in provider.discover()[0].filename

    def test_an_item_missing_a_url_is_dropped(self) -> None:
        provider, _ = _provider([[{"title": "NCR Price Monitoring", "contentUrl": ""}]])

        assert provider.discover() == []

    def test_a_graphql_error_is_raised_not_swallowed(self) -> None:
        # An empty run is a legitimate outcome, so a schema change that returns
        # nothing must not be indistinguishable from a quiet week.
        class _Erroring(_FakeSession):
            def post(self, url: str, json: dict, timeout: int) -> _FakeResponse:
                return _FakeResponse({"errors": [{"message": "Field undefined"}]})

        settings = get_settings()
        provider = GraphQlDiscoveryProvider(settings, _Erroring([]))  # type: ignore[arg-type]

        with pytest.raises(DiscoveryError, match="Field undefined"):
            provider.discover()

    def test_it_never_sends_search_or_filter(self) -> None:
        # Both are deliberately unused: search is not relevance-ranked here and
        # OData filter returns nothing. Sending either would silently drop
        # reports while appearing to work.
        provider, session = _provider([[_doc("NCR Price Monitoring 07282026.pdf")]])
        provider.discover()

        query = session.requests[0]["query"]

        assert "flatten: true" in query
        assert "search" not in query
        assert "filter" not in query


class TestPagination:
    def test_it_stops_once_a_page_falls_outside_the_lookback(self) -> None:
        # The library is newest-first, so an old page means every later page is
        # older. Without this a daily run walks all 14,898 documents.
        pages = [
            [_doc("NCR Price Monitoring 07282026.pdf", days_ago=1)],
            [_doc("NCR Price Monitoring 01012020.pdf", days_ago=900)],
            [_doc("NCR Price Monitoring 01012019.pdf", days_ago=1200)],
        ]
        provider, session = _provider(pages, lookback_days=14, max_candidates_per_run=100)

        provider.discover()

        assert len(session.requests) == 2

    def test_it_stops_once_it_has_enough_candidates(self) -> None:
        pages = [[_doc(f"NCR Price Monitoring 0{n}012026.pdf") for n in range(1, 4)]] * 3
        provider, session = _provider(pages, max_candidates_per_run=2)

        found = provider.discover()

        assert len(found) == 2
        assert len(session.requests) == 1

    def test_it_respects_the_page_ceiling(self) -> None:
        # A misconfigured lookback must not walk the archive.
        pages = [[_doc("NCR Price Monitoring 07282026.pdf", days_ago=0)] for _ in range(10)]
        provider, session = _provider(
            pages, graphql_max_pages=2, lookback_days=3650, max_candidates_per_run=100
        )

        provider.discover()

        assert len(session.requests) == 2

    def test_an_empty_page_ends_the_walk(self) -> None:
        provider, session = _provider(
            [[_doc("NCR Price Monitoring 07282026.pdf")], []],
            max_candidates_per_run=100,
            lookback_days=3650,
        )

        provider.discover()

        assert len(session.requests) == 2

    def test_a_daily_run_reads_one_page(self) -> None:
        # The point of the whole exercise: the newest reports are on page one.
        pages = [
            [_doc("NCR Price Monitoring 07282026.pdf", days_ago=1)] * 50,
            [_doc("NCR Price Monitoring 01012020.pdf", days_ago=900)],
        ]
        provider, session = _provider(pages, max_candidates_per_run=40)

        assert len(provider.discover()) == 40
        assert len(session.requests) == 1


class TestDuplicateHandling:
    def test_the_same_document_twice_yields_two_candidates(self) -> None:
        # Discovery does not deduplicate. The checksum of the downloaded bytes
        # is what identifies a report, and that is the importer's job — a
        # provider guessing at identity from a URL would let a re-issued
        # document through under a new name.
        doc = _doc("NCR Price Monitoring 07282026.pdf")
        provider, _ = _provider([[doc, doc]], max_candidates_per_run=100)

        assert len(provider.discover()) == 2


class TestManualSeedProvider:
    def test_it_yields_the_urls_it_was_given(self) -> None:
        provider = ManualSeedProvider(urls=["https://example.test/a.pdf"])

        found = provider.discover()

        assert len(found) == 1
        assert found[0].url == "https://example.test/a.pdf"
        assert found[0].source == "manual"

    def test_it_satisfies_the_provider_interface(self) -> None:
        # Which is what lets `--url` and a scheduled run share one code path.
        assert isinstance(ManualSeedProvider(urls=[]), DiscoveryProvider)
        assert isinstance(GraphQlDiscoveryProvider(get_settings()), DiscoveryProvider)


def test_discovered_pdf_carries_the_documented_fields() -> None:
    candidate = DiscoveredPdf(
        title="NCR Price Monitoring 07282026.pdf",
        content_url="https://example.test/x.pdf",
    )

    for attribute in (
        "title",
        "content_url",
        "date_modified",
        "region",
        "publication_date",
        "checksum",
    ):
        assert hasattr(candidate, attribute)
