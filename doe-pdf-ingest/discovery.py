"""Finds newly published DOE price monitoring PDFs.

The DOE publishes these as attachments on weekly articles. There is no index,
no feed and no API: ``prod-cms.doe.gov.ph`` is a Liferay instance whose
document library is not browsable, and the only way to a PDF is the article
that links it.

Discovery reads the listing pages, which embed the attachment links in their
cards:

    /articles/group/liquid-fuels?category=Price+Monitoring   (paginated)
        ↓  attachment links, straight from the cards
    prod-cms.doe.gov.ph/documents/d/guest/{name}

It deliberately does *not* follow the individual article pages. Those are
client-rendered shells: fetched over HTTP they contain the site chrome, the
title and nothing else, and even in a real browser the body arrives empty. The
Liferay content API behind them (`/o/headless-delivery/v1.0/`) answers 404 for
the ids in the article URLs, which are not content ids. Crawling them costs a
request each and returns nothing — the listing already has what is needed.

**Filenames are not trusted for anything.** They are inconsistent in a way that
would break any convention-based approach:

    ncr-price-monitoring-07282026-pdf     region and date, with a -pdf suffix
    ncr-price-monitoring-11112025         the same thing, without the suffix
    region-iv-a-calabarzon-20-pdf         region, and a sequence number
    region-v-bicol-8-pdf                  no date at all
    petro_vis_2024-feb-27                 a different path and a third convention

A crawler that guessed `ncr-price-monitoring-{date}-pdf` would find NCR and
miss every region. So the filename is used only as a weak hint for *whether a
link is worth downloading*; the authoritative region and coverage dates are
read out of the PDF's own header by :mod:`extractor`.

This module reads HTML to find links. That is not table scraping — no figure
in this system comes from a web page. Every price is extracted from a PDF.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup

from logger import get_logger
from settings import Settings, get_settings

log = get_logger(__name__)

#: Words in a link or its label that suggest a fuel price monitoring document.
#: Deliberately broad — a false positive costs one download that the extractor
#: then rejects, while a false negative loses a region for the week.
_RELEVANT = re.compile(
    r"(price[-_\s]*monitoring|prevailing|retail[-_\s]*price|oil[-_\s]*monitor"
    r"|petro_|price[-_\s]*watch|region|ncr|car\b|mimaropa|calabarzon|bicol)",
    re.IGNORECASE,
)

#: Liferay's public document path.
_DOCUMENT_PATH = re.compile(r"/documents/d/", re.IGNORECASE)

#: Site chrome served from the same document path — logos, social icons,
#: seals. Without this every page contributes a dozen candidate "PDFs" that
#: are actually PNGs, each costing a download to reject.
_CHROME = re.compile(
    r"(logo|facebook|instagram|twitter|viber|youtube|tiktok|seal|banner|icon"
    r"|bagong_ph|transparency|foi|dpo)",
    re.IGNORECASE,
)


class DiscoveryError(RuntimeError):
    """The listing could not be crawled."""


@dataclass(frozen=True)
class DiscoveredPdf:
    """A candidate PDF, before anything has been downloaded or verified."""

    url: str
    #: Link text or the article title. Kept for the run log, never parsed for
    #: region or dates — see the module docstring.
    label: str
    article_url: str
    article_title: str

    @property
    def filename(self) -> str:
        """The last path segment, used as the stored filename."""
        name = urlparse(self.url).path.rstrip("/").rsplit("/", 1)[-1]

        return name if name.lower().endswith(".pdf") else f"{name}.pdf"


class PdfDiscovery:
    """Crawls the DOE article listings for PDF attachments."""

    def __init__(self, settings: Settings | None = None, session: requests.Session | None = None):
        self.settings = settings or get_settings()
        self.session = session or requests.Session()
        self.session.headers.update({"User-Agent": self.settings.user_agent})

    # -- crawl ---------------------------------------------------------------

    def discover(self, pages: int | None = None) -> list[DiscoveredPdf]:
        """Every PDF linked from the recent listings, newest first.

        A failing category or page is logged and skipped rather than raised:
        the DOE portal is not highly available, and losing Price Monitoring
        because its listing 500s should not cost Oil Monitor too.
        """
        pages = pages or self.settings.listing_pages
        seen: set[str] = set()
        found: list[DiscoveredPdf] = []

        for category in self.settings.listing_categories:
            for page in range(1, pages + 1):
                url = self.settings.listing_url(category, page)

                try:
                    soup = self._fetch_html(url)
                except DiscoveryError as exc:
                    log.warning("Listing unavailable", extra={"url": url, "error": str(exc)})
                    continue

                page_pdfs = self._document_links(soup, url, category)

                if not page_pdfs:
                    # No documents on this page means the end of the archive
                    # for this category, not an error.
                    break

                for pdf in page_pdfs:
                    if pdf.url in seen:
                        continue

                    seen.add(pdf.url)
                    found.append(pdf)

        log.info("Discovery finished", extra={"pdfs": len(found), "pages": pages})

        return found

    def _document_links(
        self, soup: BeautifulSoup, source: str, category: str
    ) -> list[DiscoveredPdf]:
        """Attachment links on a listing page.

        Both anchors and image sources are considered: the cards link some
        documents and embed others as thumbnails whose URL is the document
        itself.
        """
        pdfs: list[DiscoveredPdf] = []

        for element in soup.find_all(["a", "img"]):
            href = str(element.get("href") or element.get("src") or "")
            label = (
                element.get_text(strip=True)
                if element.name == "a"
                else str(element.get("alt") or "")
            )

            if not self._looks_like_document(href, label):
                continue

            pdfs.append(
                DiscoveredPdf(
                    url=urljoin(self.settings.cms_base_url, href),
                    label=label or category,
                    article_url=source,
                    article_title=category,
                )
            )

        return pdfs

    def _article_links(self, listing_url: str) -> list[tuple[str, str]]:
        """`(url, title)` for every article on a listing page."""
        soup = self._fetch_html(listing_url)
        links: list[tuple[str, str]] = []
        seen: set[str] = set()

        for anchor in soup.find_all("a", href=True):
            href = str(anchor["href"])

            # Article hrefs are /articles/{id}--{slug}. The id requirement is
            # what keeps category and pagination links out.
            if not re.search(r"/articles/\d+--", href):
                continue

            url = urljoin(self.settings.portal_base_url, href)

            if url in seen:
                continue

            seen.add(url)
            links.append((url, anchor.get_text(strip=True)))

        return links

    def _article_pdfs(self, article_url: str, title: str) -> list[DiscoveredPdf]:
        """Attachment links on one article."""
        try:
            soup = self._fetch_html(article_url)
        except DiscoveryError as exc:
            log.warning("Article unavailable", extra={"url": article_url, "error": str(exc)})
            return []

        pdfs: list[DiscoveredPdf] = []

        for anchor in soup.find_all("a", href=True):
            href = str(anchor["href"])
            label = anchor.get_text(strip=True)

            if not self._looks_like_document(href, label):
                continue

            pdfs.append(
                DiscoveredPdf(
                    url=urljoin(self.settings.cms_base_url, href),
                    label=label or title,
                    article_url=article_url,
                    article_title=title,
                )
            )

        return pdfs

    def _looks_like_document(self, href: str, label: str) -> bool:
        """Whether a link is worth downloading.

        A hint only. The extractor decides whether the file is really a price
        monitoring report, because that question is answered by the PDF's own
        header and not by its URL.
        """
        if not _DOCUMENT_PATH.search(href):
            return False

        if _CHROME.search(href):
            return False

        return bool(_RELEVANT.search(href) or _RELEVANT.search(label))

    # -- http ----------------------------------------------------------------

    def _fetch_html(self, url: str) -> BeautifulSoup:
        # Deliberate pacing. This is a government portal being polled by a
        # daily job; there is nothing to gain by hammering it.
        time.sleep(self.settings.request_delay_s)

        try:
            response = self.session.get(url, timeout=self.settings.request_timeout_s)
            response.raise_for_status()
        except requests.RequestException as exc:
            raise DiscoveryError(str(exc)) from exc

        return BeautifulSoup(response.text, "lxml")
