"""Finding newly published DOE price monitoring PDFs.

Discovery sits behind a provider interface so the pipeline never knows where
candidates come from. The shipping implementation queries Liferay's GraphQL
API; the listing crawler that preceded it has been removed.

Why the crawler was replaced
----------------------------

It read the portal's article listings. That worked for the field offices
publishing under "Price Monitoring" and never once reached NCR — those reports
exist and are publicly served, but the listings linking them use a different
query grammar, and NCR sat at position 2,382 of 3,251 candidates. No page limit
or category balancing fixes that, because the ordering itself carried no
meaning.

The GraphQL API returns the document library ordered by modification date, so
the newest reports are on page one by construction.

The endpoint
------------

    POST https://prod-cms.doe.gov.ph/o/graphql

    documents(
        siteKey: "guest",
        flatten: true,
        pageSize: 100,
        page: N,
        sort: "dateModified:desc"
    )

``flatten: true`` is mandatory, and is the whole reason this took so long to
find. It defaults to false, which returns only the library's *root folder* —
58 documents, not one of them a price report. With it, the same site key
returns 14,898 and the current NCR report is on page one. The site key was
never wrong; the missing argument was.

``search`` and ``filter`` are deliberately unused. ``search`` is not
relevance-ranked here — "NCR Price Monitoring" returns 4,621 documents, no
query returns fewer, and the ordering does not change — while OData
``filter: contains(title,'NCR')`` returns zero. Either would look like it was
working while quietly dropping reports. Titles are classified in this process
instead, where the rules are visible and tested.
"""

from __future__ import annotations

import re
import time
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import UTC, date, datetime, timedelta
from urllib.parse import unquote, urljoin, urlparse

import requests

from logger import get_logger
from settings import Settings, get_settings

log = get_logger(__name__)


class DiscoveryError(RuntimeError):
    """Candidates could not be retrieved."""


#: Titles that name a fuel price publication.
#:
#: Matched against the document *title*, which the CMS keeps clean — "NCR Price
#: Monitoring 07282026.pdf", "VFO PRICE MONITORING 080426_with LGU and
#: Field.pdf" — unlike the friendly-URL filenames, which run to four
#: irreconcilable conventions.
_RELEVANT_TITLE = re.compile(
    r"(price\s*monitoring|pump\s*price|prevailing\s*retail|retail\s*pump)",
    re.IGNORECASE,
)

#: Region hints in a title, best-effort only.
#:
#: The authoritative region is read from the PDF's own header by the extractor.
#: This exists so a run's log says something useful before anything is
#: downloaded.
_REGION_HINTS: tuple[tuple[str, str], ...] = (
    (r"\bNCR\b", "NCR"),
    (r"\bCAR\b", "CAR"),
    (r"\bBARMM\b", "BARMM"),
    (r"\bVFO\b|visayas", "Visayas"),
    (r"north\s*luzon", "North Luzon"),
    (r"south\s*luzon", "South Luzon"),
    (r"\bLFRO\b", "Field Office"),
    (r"region\s*[IVX0-9]+", "Region"),
)

#: A date embedded in a title: MMDDYYYY or MMDDYY.
_TITLE_DATE = re.compile(r"(?<!\d)(\d{2})(\d{2})(\d{4}|\d{2})(?!\d)")


@dataclass(frozen=True)
class DiscoveredPdf:
    """A candidate document, before anything has been downloaded.

    ``region`` and ``publication_date`` are hints from the title and the CMS
    metadata. Neither is authoritative — the extractor reads both out of the
    PDF, because a title can be wrong and a filename routinely is.
    ``checksum`` is unknown until the bytes are fetched, and is carried so a
    provider that already knows it can supply it.
    """

    title: str
    content_url: str
    date_modified: datetime | None = None
    region: str | None = None
    publication_date: date | None = None
    checksum: str | None = None

    #: Which provider produced this, for the run log.
    source: str = "graphql"

    @property
    def url(self) -> str:
        """Absolute URL. The pipeline and downloader use this."""
        return self.content_url

    @property
    def filename(self) -> str:
        """A filename for the stored PDF.

        Derived from the *title*, not the URL. Liferay's contentUrl ends in a
        UUID — `/documents/20119/0/NCR Price Monitoring 07282026.pdf/8c3f…` —
        so the last path segment is an opaque identifier carrying nothing.
        That matters beyond tidiness: the extractor falls back to the filename
        for a coverage week when the document states none, which the Visayas
        reports never do. A UUID there silently costs every one of them its
        dates.
        """
        stem = re.sub(r"[^A-Za-z0-9._-]+", "-", unquote(self.title)).strip("-")

        if not stem:
            stem = urlparse(self.content_url).path.rstrip("/").rsplit("/", 1)[-1] or "document"

        return stem if stem.lower().endswith(".pdf") else f"{stem}.pdf"


class DiscoveryProvider(ABC):
    """Where candidate documents come from.

    The pipeline depends on this and nothing below it, so a provider can be
    swapped without touching ingestion. A sitemap or a feed would each be
    another implementation of this one method.
    """

    name: str = "provider"

    @abstractmethod
    def discover(self, limit: int | None = None) -> list[DiscoveredPdf]:
        """Candidate documents, newest first."""


def classify_title(title: str) -> tuple[bool, str | None]:
    """Whether a title names a price publication, and which region it hints at.

    Returns ``(is_relevant, region_hint)``. The hint is advisory: the region
    stored against a report is always the one printed inside the document.
    """
    if not _RELEVANT_TITLE.search(title):
        return False, None

    for pattern, region in _REGION_HINTS:
        if re.search(pattern, title, re.IGNORECASE):
            return True, region

    return True, None


def date_from_title(title: str) -> date | None:
    """A publication date embedded in a title, if there is one."""
    for match in _TITLE_DATE.finditer(title):
        month, day, year = match.groups()
        full_year = int(year) if len(year) == 4 else 2000 + int(year)

        try:
            return date(full_year, int(month), int(day))
        except ValueError:
            # A sequence number or a page count, not a date.
            continue

    return None


def _parse_timestamp(value: object) -> datetime | None:
    """Parse the CMS's ISO timestamps, which end in Z."""
    if not isinstance(value, str) or not value:
        return None

    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None

    return parsed if parsed.tzinfo else parsed.replace(tzinfo=UTC)


class GraphQlDiscoveryProvider(DiscoveryProvider):
    """Reads the CMS document library over GraphQL."""

    name = "graphql"

    _QUERY = """
        query Documents($siteKey: String!, $pageSize: Int!, $page: Int!) {
          documents(
            siteKey: $siteKey
            flatten: true
            pageSize: $pageSize
            page: $page
            sort: "dateModified:desc"
          ) {
            totalCount
            items { id title contentUrl dateModified }
          }
        }
    """

    def __init__(self, settings: Settings | None = None, session: requests.Session | None = None):
        self.settings = settings or get_settings()
        self.session = session or requests.Session()
        self.session.headers.update(
            {"User-Agent": self.settings.user_agent, "Content-Type": "application/json"}
        )

    def discover(self, limit: int | None = None) -> list[DiscoveredPdf]:
        """Walk pages newest-first until there is no reason to continue.

        Three independent stops, because any one alone fails somewhere: the
        lookback window keeps a daily run to page one, the page cap bounds a
        backfill, and the limit stops early once enough candidates are in hand.
        Without them this would enumerate all 14,898 documents every morning to
        find the two that are new.
        """
        limit = limit or self.settings.max_candidates_per_run
        cutoff = datetime.now(tz=UTC) - timedelta(days=self.settings.lookback_days)

        found: list[DiscoveredPdf] = []
        scanned = 0
        pages = 0
        started = time.monotonic()

        for page in range(1, self.settings.graphql_max_pages + 1):
            items = self._fetch_page(page)
            pages += 1

            if not items:
                break

            scanned += len(items)
            oldest: datetime | None = None

            for item in items:
                modified = _parse_timestamp(item.get("dateModified"))

                if modified is not None:
                    oldest = modified

                candidate = self._to_candidate(item, modified)

                if candidate is not None:
                    found.append(candidate)

            if len(found) >= limit:
                break

            # The library is sorted newest first, so once a page ends older
            # than the window, every later page is older still.
            if oldest is not None and oldest < cutoff:
                break

        elapsed = time.monotonic() - started

        log.info(
            "Discovery finished in %.2fs: %d scanned, %d matched across %d page(s)",
            elapsed,
            scanned,
            len(found),
            pages,
            extra={
                "provider": self.name,
                "scanned": scanned,
                "matched": len(found),
                "pages": pages,
                "seconds": round(elapsed, 3),
            },
        )

        return found[:limit]

    def _to_candidate(self, item: dict, modified: datetime | None) -> DiscoveredPdf | None:
        """Turn one API item into a candidate, or reject it."""
        title = str(item.get("title") or "").strip()
        content_url = str(item.get("contentUrl") or "").strip()

        if not title or not content_url:
            return None

        relevant, region = classify_title(title)

        if not relevant:
            return None

        return DiscoveredPdf(
            title=title,
            content_url=urljoin(self.settings.cms_base_url, content_url),
            date_modified=modified,
            region=region,
            # The title's own date where it has one, otherwise when the CMS
            # last touched the file. Both are hints; the extractor reads the
            # coverage week out of the document itself.
            publication_date=date_from_title(title) or (modified.date() if modified else None),
        )

    def _fetch_page(self, page: int) -> list[dict]:
        """One page of the document library."""
        payload = {
            "query": self._QUERY,
            "variables": {
                "siteKey": self.settings.graphql_site_key,
                "pageSize": self.settings.graphql_page_size,
                "page": page,
            },
        }

        started = time.monotonic()

        try:
            response = self.session.post(
                self.settings.graphql_endpoint,
                json=payload,
                timeout=self.settings.request_timeout_s,
            )
            response.raise_for_status()
            body = response.json()
        except (requests.RequestException, ValueError) as exc:
            raise DiscoveryError(f"GraphQL page {page} failed: {exc}") from exc

        log.debug(
            "GraphQL page %d in %.3fs",
            page,
            time.monotonic() - started,
            extra={"page": page, "seconds": round(time.monotonic() - started, 3)},
        )

        if body.get("errors"):
            # Surfaced rather than swallowed: a schema change here means no
            # discovery at all, and an empty run is otherwise a valid outcome.
            message = body["errors"][0].get("message", "unknown error")
            raise DiscoveryError(f"GraphQL page {page} returned an error: {message}")

        documents = (body.get("data") or {}).get("documents") or {}
        items = documents.get("items") or []

        return [item for item in items if isinstance(item, dict)]


@dataclass
class ManualSeedProvider(DiscoveryProvider):
    """Candidates supplied directly, for `--url` and for a fixed replay set.

    Keeps the pipeline free of special cases: one URL is a provider yielding
    one candidate, not a branch in the run.
    """

    urls: list[str] = field(default_factory=list)
    name: str = "manual"

    def discover(self, limit: int | None = None) -> list[DiscoveredPdf]:
        candidates = [
            DiscoveredPdf(
                title=urlparse(url).path.rsplit("/", 1)[-1],
                content_url=url,
                source=self.name,
            )
            for url in self.urls
        ]

        return candidates[:limit] if limit else candidates


def build_provider(settings: Settings | None = None) -> DiscoveryProvider:
    """The configured discovery provider."""
    return GraphQlDiscoveryProvider(settings or get_settings())
