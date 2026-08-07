"""Extracts the price table out of a DOE monitoring PDF.

Three extractors are implemented and every one of them is *scored*; the highest
scoring result wins. That is what the pipeline asks for — pick whichever
produces the best table — and it matters here because no single extractor
handles every region.

A word about ordering, because it is not the obvious one.

Camelot and tabula both work by reconstructing a grid. These documents defeat
that: the DOE rules a box around each *area* block but draws no line between
the product rows inside it, so a grid-based reader merges RON 100 through
KEROSENE into a single row. That is not a tuning problem, it is the document.
Verified against the real NCR report, pdfplumber's own ``extract_tables`` puts
all six products' prices into the RON 100 row.

Plain text extraction is worse, and dangerously so. A blank brand column
produces no text at all, so a RON 95 line reads::

    79.50 87.50 87.60 93.00 93.90 93.90 78.30 81.90 ...

and nothing in that string says which brands those pairs belong to. Splitting
on whitespace assigns them left to right and silently attributes Shell's price
to Caltex whenever a brand ahead of them is blank. It is the same shape of bug
as a mis-aligned null mask: complete, plausible, entirely wrong, undetectable
downstream.

So the primary extractor is coordinate-based — every word is placed in a column
by its x position, against boundaries derived from the header row that document
itself prints. Blank columns stay blank because nothing lands in them. Camelot
and tabula remain in the chain and are tried and scored, in case a future
layout suits them better.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date, timedelta
from pathlib import Path
from typing import Any

from logger import get_logger
from settings import Settings, get_settings

log = get_logger(__name__)


class ExtractionError(RuntimeError):
    """No extractor produced a usable table."""


# --- vocabulary -------------------------------------------------------------

#: Products as the DOE prints them, mapped to the platform's fuel_types.code.
#:
#: `gasoline_ron100` has no row in fuel_types today; it is carried here so the
#: value is not silently dropped, and the Laravel importer reports it as an
#: unmapped grade rather than filing it under RON 97.
PRODUCT_CODES: dict[str, str] = {
    "RON 100": "gasoline_ron100",
    "RON 97": "gasoline_ron97",
    "RON 95": "gasoline_ron95",
    "RON 91": "gasoline_ron91",
    "DIESEL PLUS": "diesel_premium",
    "DIESEL": "diesel",
    "KEROSENE": "kerosene",
}

#: Longest first, so "DIESEL PLUS" is matched before "DIESEL".
_PRODUCT_ORDER = sorted(PRODUCT_CODES, key=len, reverse=True)

#: Column headers that are not brands. Both layouts are represented: NCR leads
#: with AREA, the Visayas reports with PROVINCE and CITY/MUNICIPALITY, and the
#: last column is "COMMON PRICE" in one and bare "COMMON" in the other.
_NON_BRAND_HEADERS = {
    "AREA",
    "PROVINCE",
    "CITY/MUNICIPALITY",
    "CITY",
    "MUNICIPALITY",
    "PRODUCT",
    "OVERALL",
    "RANGE",
    "COMMON",
    "PRICE",
    "OVERALL RANGE",
    "COMMON PRICE",
}

#: Header names that carry the area a block belongs to, best first. A report
#: with both PROVINCE and CITY/MUNICIPALITY prices per city, so the city is the
#: area and the province is recorded alongside it.
_AREA_HEADERS = ("CITY/MUNICIPALITY", "CITY", "MUNICIPALITY", "AREA")


#: Dimension headings, matched by prefix.
_DIMENSION_PREFIXES = (
    "AREA",
    "PROVINCE",
    "CITY",
    "MUNICIPALITY",
    "PRODUCT",
    "OVERALL",
    "COMMON",
    "RANGE",
    "PRICE",
    # "Row" and "Rowcol" are spreadsheet gridlines the DOE's export leaves in
    # the header on some pages. They are not companies, and prices filed under
    # them are cell indices.
    "ROW",
)


def is_brand_column(name: str) -> bool:
    """Whether a header names a company rather than a dimension.

    Matched by prefix, not equality. The DOE's own export appends artifacts to
    some headings — "Common Price Rowcol" appears on the Cebu pages — and an
    exact-match test admits that as a brand, then files spreadsheet row and
    column indices under it as prices.
    """
    upper = name.upper()

    if any(upper.startswith(prefix) for prefix in _DIMENSION_PREFIXES):
        return False

    tokens = upper.split()

    # Two headings printed on top of each other come out of the text layer
    # interleaved character by character: "COMMON" over "PRICE" arrives as
    # "C P O R M Ic M E O N". A real company name is words, not a scatter of
    # single letters, and admitting one creates a brand that never existed.
    return not (tokens and sum(len(token) == 1 for token in tokens) > len(tokens) / 2)


_MONTHS = "january|february|march|april|may|june|july|august|september|october|november|december"

#: "(For the week of July 28 - August 3, 2026)"
_COVERAGE = re.compile(
    rf"week\s+of\s+({_MONTHS})\s+(\d{{1,2}})\s*[-–]\s*(?:({_MONTHS})\s+)?(\d{{1,2}}),?\s*(\d{{4}})",
    re.IGNORECASE,
)

#: "Date of Monitoring: July 28-31, 2026", or "August 04-10, 2026".
#:
#: The trailing year is captured because the Visayas layout prints this line on
#: its last page and states no coverage week anywhere in the document — so
#: there is no other year to borrow. The day range is matched but discarded:
#: the DOE monitors over several days and records the first.
_MONITORING = re.compile(
    rf"date\s+of\s+monitoring:?\s*({_MONTHS})\s+(\d{{1,2}})"
    rf"(?:\s*[-–]\s*(?:{_MONTHS}\s+)?\d{{1,2}})?"
    rf"\s*,?\s*(\d{{4}})?",
    re.IGNORECASE,
)

#: The region line, printed under the title.
_REGION_LINE = re.compile(
    r"^\s*\(?((?:NCR|CAR|BARMM|Regions?\s+[IVXAB0-9\-]+(?:\s*\([^)]*\))?"
    r"|[A-Z][A-Za-z\s\-]{2,40}))\)?\s*$",
)

#: Region as it appears anywhere in the first few lines. The Visayas field
#: office heads its reports "(REGIONS 6-8)" — arabic numerals, plural, a range
#: and no "Region N" — while NCR prints a bare "NCR" and Luzon prints
#: "Region IV-A (CALABARZON)". One pattern has to admit all three.
_REGION_ANYWHERE = re.compile(
    r"\b(NCR|CAR|BARMM|Regions?\s+(?:[IVX]+(?:-[AB])?|\d+(?:\s*-\s*\d+)?)"
    r"(?:\s*\([^)]*\))?)",
    re.IGNORECASE,
)

#: A coverage week spelled out in a filename: "for-june-2-8-2026".
_FILENAME_WORDS = re.compile(
    rf"({_MONTHS})[-_\s]+(\d{{1,2}})[-_\s]+(\d{{1,2}})[-_\s]+(\d{{4}})",
    re.IGNORECASE,
)

#: A date in a filename: MMDDYYYY or MMDDYY.
#:
#: Needed because some reports state no dates at all. The Visayas documents say
#: only "(For the week: Tuesday - Monday)" — a schedule, not a week — so the
#: filename is the sole source. That does not soften the rule elsewhere: where
#: the document states its coverage, the document wins, and this is consulted
#: only when it does not.
_FILENAME_DATE = re.compile(r"(?<!\d)(\d{2})(\d{2})(\d{4}|\d{2})(?!\d)")

_NUMBER = re.compile(r"^\d{1,3}(?:\.\d{1,2})?$")

#: How far apart two words' baselines may be and still be one row. The DOE
#: prints product rows ~9pt apart, so this is comfortably below a real gap
#: while absorbing the sub-point jitter within a single line.
_ROW_TOLERANCE = 3.0

#: Values the DOE prints for "no data".
_NULL_TOKENS = {"#N/A", "N/A", "NONE", "-", "--", ""}


@dataclass
class AreaPrice:
    """One area × product × brand cell: the brand's price range in that area."""

    area: str
    #: Only the layouts that publish one. Two municipalities can share a name
    #: across provinces, so without it their rows would collide on the key.
    province: str | None
    product: str
    fuel_code: str
    brand: str | None
    min_price: float | None
    max_price: float | None
    common_price: float | None = None

    @property
    def is_overall(self) -> bool:
        """Whether this is the area's overall range rather than one brand's."""
        return self.brand is None


@dataclass
class ExtractedReport:
    """Everything read out of one PDF."""

    region: str | None
    coverage_start: date | None
    coverage_end: date | None
    monitoring_date: date | None
    brands: list[str] = field(default_factory=list)
    prices: list[AreaPrice] = field(default_factory=list)
    extractor: str = "unknown"
    quality: float = 0.0
    warnings: list[str] = field(default_factory=list)

    @property
    def areas(self) -> list[str]:
        seen: dict[str, None] = {}
        for price in self.prices:
            seen.setdefault(price.area, None)
        return list(seen)

    def summary(self) -> str:
        return (
            f"region={self.region} coverage={self.coverage_start}..{self.coverage_end} "
            f"areas={len(self.areas)} rows={len(self.prices)} "
            f"extractor={self.extractor} quality={self.quality:.2f}"
        )


def score(report: ExtractedReport) -> float:
    """How much to trust an extraction, 0 to 1.

    Four things are measured, because a table can fail in four ways and only
    the first is obvious:

      * it found priced rows at all;
      * it identified the region — without one the report cannot be filed;
      * it identified the coverage week — without it the report cannot be dated
        or deduplicated;
      * the prices it found are plausible numbers rather than page furniture.
    """
    if not report.prices:
        return 0.0

    priced = [p for p in report.prices if p.min_price is not None or p.max_price is not None]

    if not priced:
        return 0.0

    settings = get_settings()
    plausible = sum(
        1
        for p in priced
        for value in (p.min_price, p.max_price)
        if value is not None
        and settings.min_plausible_price <= value <= settings.max_plausible_price
    )
    total_values = sum(
        1 for p in priced for value in (p.min_price, p.max_price) if value is not None
    )

    coverage = 0.45 * (plausible / total_values if total_values else 0)
    density = 0.25 * min(1.0, len(priced) / 40)
    has_region = 0.15 if report.region else 0.0
    has_dates = 0.15 if report.coverage_start and report.coverage_end else 0.0

    return round(coverage + density + has_region + has_dates, 4)


# --- header ------------------------------------------------------------------


def _month_number(name: str) -> int:
    months = [
        "january",
        "february",
        "march",
        "april",
        "may",
        "june",
        "july",
        "august",
        "september",
        "october",
        "november",
        "december",
    ]
    return months.index(name.strip().lower()) + 1


def parse_header(text: str) -> tuple[str | None, date | None, date | None, date | None]:
    """Region, coverage start/end and monitoring date, from the page text.

    Read from the document rather than from its filename, which cannot be
    trusted: the DOE publishes ``region-v-bicol-8-pdf`` with no date in it at
    all, and ``ncr-price-monitoring-11112025`` without the ``-pdf`` suffix its
    siblings carry.
    """
    region = None
    lines = [line.strip() for line in text.splitlines() if line.strip()]

    for index, line in enumerate(lines[:8]):
        if "price monitoring" in line.lower() and index + 1 < len(lines):
            candidate = lines[index + 1]
            match = _REGION_LINE.match(candidate)
            if match:
                region = match.group(1).strip()
            break

    if region is None:
        # Some layouts put the region on the title line, and the Visayas
        # reports lead with it and print no title at all.
        for line in lines[:6]:
            match = _REGION_ANYWHERE.search(line)

            if match:
                region = " ".join(match.group(1).split()).upper()
                break

    start = end = None
    coverage = _COVERAGE.search(text)

    if coverage:
        start_month, start_day, end_month, end_day, year = coverage.groups()
        try:
            start = date(int(year), _month_number(start_month), int(start_day))
            end = date(int(year), _month_number(end_month or start_month), int(end_day))
            # "December 29 - January 4, 2027" prints one year; the start is in
            # the previous one. Without this the range runs backwards by ~360
            # days and every duplicate check against it fails.
            if end < start:
                start = date(start.year - 1, start.month, start.day)
        except ValueError:
            start = end = None

    return region, start, end, monitoring_date(text, fallback_year=start.year if start else None)


def monitoring_date(text: str, fallback_year: int | None = None) -> date | None:
    """The date the DOE walked the forecourts, if the document states it.

    Separate from [`parse_header`] because the two layouts supply the year from
    different places. NCR prints the coverage week and the monitoring date
    together at the top; the Visayas report prints no coverage week at all and
    puts the monitoring line on its last page, carrying its own year. Requiring
    a coverage start before reading this line — as this did — silently dropped
    the date for every Visayas report, which is where `monitoring_date` being
    null for all of REGIONS 6-8 came from.
    """
    monitored = _MONITORING.search(text)

    if not monitored:
        return None

    year = monitored.group(3)

    if year is None and fallback_year is None:
        # Better absent than invented. A monitoring date in the wrong year
        # fails validation against the coverage week and takes the whole
        # report down with it.
        return None

    try:
        return date(
            int(year) if year else int(fallback_year),  # type: ignore[arg-type]
            _month_number(monitored.group(1)),
            int(monitored.group(2)),
        )
    except ValueError:
        return None


# --- the coordinate extractor -------------------------------------------------


def coverage_from_filename(filename: str) -> tuple[date, date] | None:
    """A coverage week derived from a filename, or None.

    The date names the week's first day: `ncr-price-monitoring-07282026`
    covers 28 July to 3 August, and `vfo-lf-price-monitoring-112525` starts on
    Tuesday 25 November 2025, which is what "(For the week: Tuesday - Monday)"
    means in the Visayas documents.

    Only consulted when the document states no coverage of its own.
    """
    # The spelled-out form first: "june-2-8-2026" also contains the digits
    # "2026", and the numeric pattern would read a fragment of it as a date.
    words = _FILENAME_WORDS.search(filename)

    if words is not None:
        month, first, last, year = words.groups()

        try:
            start = date(int(year), _month_number(month), int(first))
            end = date(int(year), _month_number(month), int(last))
        except ValueError:
            start = end = None

        if start is not None and end is not None and end >= start:
            return start, end

    for match in _FILENAME_DATE.finditer(filename):
        month, day, year = match.groups()

        # Two-digit years are this century. These publications began in the
        # 2010s and a DOE report from 1925 is not a thing.
        full_year = int(year) if len(year) == 4 else 2000 + int(year)

        try:
            start = date(full_year, int(month), int(day))
        except ValueError:
            # Not a date — a sequence number, a page count, an office code.
            continue

        return start, start + timedelta(days=6)

    return None


def _to_price(token: str) -> float | None:
    token = token.strip().replace(",", "")

    if token.upper() in _NULL_TOKENS or not _NUMBER.match(token):
        return None

    value = float(token)

    # 0.00 is the DOE's other way of writing "no data" — it appears in cells
    # beside a real range. Storing it would drag every minimum to zero.
    return value if value > 0 else None


def extract_with_pdfplumber(path: Path, settings: Settings) -> ExtractedReport:
    """Place every word in a column by its x position.

    The header row the document prints is what defines the columns; boundaries
    are the midpoints between adjacent header centres. Nothing is assumed about
    which brands appear or in what order, so a region that lists eight brands
    instead of ten reads correctly without configuration.
    """
    import pdfplumber  # noqa: PLC0415 - optional at import time, required here

    report = ExtractedReport(
        region=None, coverage_start=None, coverage_end=None, monitoring_date=None
    )
    all_prices: list[AreaPrice] = []
    full_text: list[str] = []
    # Spans the document, not the page. A stale label the DOE carries over from
    # the previous page is only distinguishable from the live one by having
    # been used already, so this cannot reset between pages.
    used_areas: set[str] = set()

    with pdfplumber.open(str(path)) as pdf:
        for page in pdf.pages:
            text = page.extract_text() or ""
            full_text.append(text)

            words = page.extract_words(use_text_flow=False, keep_blank_chars=False)

            if not words:
                continue

            header = _header_columns(words)

            if header is None:
                # A page with no header is the summary page or a continuation;
                # its text still contributes to the header parse above.
                continue

            columns, header_top = header

            report.brands = report.brands or [
                name
                for name, _, _ in columns
                if name not in _NON_BRAND_HEADERS and name not in ("OVERALL RANGE", "COMMON PRICE")
            ]

            all_prices.extend(
                _rows_from_words(words, page.chars, columns, header_top, report, used_areas)
            )

    joined = "\n".join(full_text)
    region, start, end, monitoring = parse_header(joined)

    if start is None or end is None:
        # The document states no coverage. The Visayas reports print
        # "(For the week: Tuesday - Monday)" — the publication schedule, not
        # the week — so their filename is the only place the date exists.
        derived = coverage_from_filename(path.name)

        if derived is not None:
            start, end = derived
            report.warnings.append(
                f"Coverage not stated in the document; taken from the filename: {start}..{end}"
            )

            # Retried now that a year exists. A layout with no coverage week is
            # exactly the one whose monitoring line may print no year either,
            # and the first attempt had nothing to resolve it against.
            monitoring = monitoring or monitoring_date(joined, fallback_year=start.year)

    report.region = region
    report.coverage_start = start
    report.coverage_end = end
    report.monitoring_date = monitoring
    report.prices = all_prices
    report.extractor = "pdfplumber-coordinates"
    report.quality = score(report)

    return report


def _header_columns(
    words: list[dict[str, Any]],
) -> tuple[list[tuple[str, float, float]], float] | None:
    """Find the table header: `(name, left, right)` per column, and its y.

    The y is returned so the caller can skip the header itself when reading
    data rows. Without it the header is parsed as a row and its AREA cell
    becomes an area named "AREA", under which every price on the page is
    then filed.
    """
    # PRODUCT is the only column heading both layouts share — NCR leads with
    # AREA, the Visayas reports with PROVINCE — so it is what the header band
    # is found by.
    anchors = [word for word in words if word["text"].strip().upper() == "PRODUCT"]

    if not anchors:
        return None

    # The header band is whatever sits on the same line as AREA/PRODUCT. Taking
    # every word on that line, rather than only names we recognise, is what
    # lets an unknown brand form its own column — the regions do not all list
    # the same companies, and a brand we failed to anticipate must not have its
    # prices absorbed into a neighbour.
    band_top = min(word["top"] for word in anchors)
    window = [word for word in words if abs(word["top"] - band_top) < 6]

    # The header is the busiest baseline in that window. A tolerance alone is
    # not enough to isolate it, and the two layouts fail it in opposite
    # directions: NCR wraps "COMMON PRICE" across baselines 3.6pt apart, so a
    # tight tolerance loses the column, while the Visayas reports print
    # "(For the week: Tuesday - Monday)" 5.5pt above the header, so a loose one
    # swallows the title and shreds the brand columns it crosses.
    baselines: dict[int, list[dict[str, Any]]] = {}

    for word in window:
        baselines.setdefault(round(word["top"]), []).append(word)

    band = max(baselines.values(), key=len)
    spans = [(word["x0"], word["x1"]) for word in band]

    # A wrapped heading continues its own column and so overlaps nothing else
    # on the header line; a title crosses several. That is what separates
    # "PRICE" under "COMMON" from a stray line of prose.
    for word in window:
        if word in band:
            continue

        # A column heading is a word. Punctuation that happens to fall in the
        # gap between two columns — the hyphen in "(For the week: Tuesday -
        # Monday)" lands between TOTAL and FLYING V — is not one, and admitting
        # it renames the column it attaches to.
        if not any(character.isalnum() for character in word["text"]):
            continue

        if any(word["x0"] < right and left < word["x1"] for left, right in spans):
            continue

        band.append(word)

    if len(band) < 6:
        return None

    if len(band) < 6:
        return None

    # "FLYING V", "OVERALL RANGE" and "COMMON PRICE" are two words each; merge
    # anything closer than a normal column gap.
    band.sort(key=lambda word: word["x0"])
    merged: list[dict[str, Any]] = []

    for word in band:
        if merged and word["x0"] - merged[-1]["x1"] < 8:
            merged[-1] = {
                "text": f"{merged[-1]['text']} {word['text']}",
                "x0": merged[-1]["x0"],
                "x1": word["x1"],
                "top": merged[-1]["top"],
            }
        else:
            merged.append(dict(word))

    # Each column is its heading's own span. Cells are assigned to the *nearest*
    # heading rather than by hard boundaries — see `column_at`.
    columns = [(word["text"].strip().upper(), word["x0"], word["x1"]) for word in merged]

    return columns, max(word["top"] for word in band)


def area_column(columns: list[tuple[str, float, float]]) -> tuple[str, float, float]:
    """The column carrying the area a block belongs to.

    NCR calls it AREA and prints it first; the Visayas reports call it
    CITY/MUNICIPALITY and print PROVINCE before it. Taking column zero — which
    is what this did — reads provinces as areas on those reports, so every
    city in Negros Occidental would be filed under one label and their prices
    would collide on the unique key.
    """
    for wanted in _AREA_HEADERS:
        for column in columns:
            if column[0].upper() == wanted:
                return column

    # No recognised heading: fall back to the first column, which is where
    # every layout seen so far puts its leftmost dimension.
    return columns[0]


def province_column(columns: list[tuple[str, float, float]]) -> tuple[str, float, float] | None:
    """The province column, on the layouts that have one."""
    for column in columns:
        if column[0].upper() == "PROVINCE":
            return column

    return None


def _labels_in_column(
    chars: list[dict[str, Any]],
    column: tuple[str, float, float],
    columns: list[tuple[str, float, float]],
    header_top: float,
) -> list[tuple[float, str]]:
    """Read the area labels straight from characters.

    Not from ``extract_words``. Two things defeat that here, and both were
    observed on the real NCR report:

      * The label sits *between* product rows — "Caloocan City" is printed at
        y=164.5 while RON 91 is at y=165.9 — because the DOE centres it
        vertically in its block. Any row bucketing tight enough to keep the
        product rows apart puts the label in a bucket of its own.
      * ``extract_words`` merges characters across those near-identical
        baselines, interleaving two labels into "MCuanlotioncluapna C Citiyty".

    At character level they are clean and unambiguous, so they are grouped by
    exact baseline and ordered by x.
    """
    wanted = column[0]

    lines: dict[float, list[dict[str, Any]]] = {}

    for char in chars:
        if char["top"] <= header_top + 2:
            continue

        centre = (char["x0"] + char["x1"]) / 2

        if column_at(centre, columns) != wanted:
            continue

        # Half a point: enough to absorb baseline jitter within one label,
        # tight enough never to join two.
        key = round(char["top"] * 2) / 2
        lines.setdefault(key, []).append(char)

    labels: list[tuple[float, str]] = []

    for top in sorted(lines):
        ordered = sorted(lines[top], key=lambda char: char["x0"])
        text = "".join(char["text"] for char in ordered)
        cleaned = _clean_area(text)

        if cleaned:
            labels.append((top, cleaned))

    return labels


def _rows_from_words(
    words: list[dict[str, Any]],
    chars: list[dict[str, Any]],
    columns: list[tuple[str, float, float]],
    header_top: float,
    report: ExtractedReport,
    used: set[str],
) -> list[AreaPrice]:
    """Group words into rows and read a price row out of each."""
    # Clustered by proximity, not by fixed-width buckets.
    #
    # `int(top // 3)` looks equivalent and is not: it splits a row whenever its
    # words straddle a multiple of three. Caloocan City's RON 95 line sits at
    # y=156.81, half a point from the 156 boundary, so part of it bucketed with
    # the row above and the row lost its prices entirely. Clustering on the gap
    # between consecutive baselines has no boundaries to straddle.
    ordered = sorted(words, key=lambda word: word["top"])
    rows: list[list[dict[str, Any]]] = []

    for word in ordered:
        # Everything at or above the header is title block or the header row
        # itself, never data.
        if word["top"] <= header_top + 2:
            continue

        if rows and word["top"] - rows[-1][0]["top"] <= _ROW_TOLERANCE:
            rows[-1].append(word)
        else:
            rows.append([word])

    # Two passes, because the area label is vertically centred in its block.
    # The DOE prints "Caloocan City" beside RON 91, the fourth of seven product
    # rows — so a single forward pass has no area for RON 100, 97 and 95 and
    # drops them. Reading every row first, then segmenting into blocks and
    # attaching each block's label, keeps them.
    parsed: list[tuple[float, tuple[str, str], dict[str, str]]] = []

    for group in rows:
        row = sorted(group, key=lambda word: word["x0"])
        cells = _cells(row, columns)
        product = _product_of(cells.get("PRODUCT", ""))

        if product is not None:
            parsed.append((min(word["top"] for word in row), product, cells))

    prices: list[AreaPrice] = []
    labels = _labels_in_column(chars, area_column(columns), columns, header_top)

    # Province labels span several city blocks, so they are matched by nearest
    # label at or above the block rather than by containment.
    province_col = province_column(columns)
    provinces = _labels_in_column(chars, province_col, columns, header_top) if province_col else []

    for block in _blocks(parsed):
        top = min(row[0] for row in block)
        bottom = max(row[0] for row in block)

        # The label printed inside this block's vertical span. Falling back to
        # the nearest one above would be how a mangled label quietly puts one
        # city's prices under another, so there is no fallback.
        candidates = [text for y, text in labels if top - 12 <= y <= bottom + 12]

        # A block can carry two labels a couple of points apart: the DOE's own
        # documents leave a stale label from the previous page sitting under
        # the real one, which is what made "Muntinlupa City" and "Caloocan
        # City" interleave into one unreadable string. Each area appears once
        # in a report, so the one not already used is the live label — taking
        # the topmost instead files a whole city's prices under another.
        area = next((text for text in candidates if text not in used), None)

        if area is None and candidates:
            report.warnings.append(
                f"Block near y={top:.0f} matched only already-used labels: {candidates}"
            )

        if area is None:
            report.warnings.append(
                f"Skipped {len(block)} rows near y={top:.0f}: no readable area label"
            )
            continue

        used.add(area)
        prices.extend(
            _block_prices(
                [(row[1], row[2]) for row in block],
                area,
                _province_for(provinces, top, bottom),
                columns,
            )
        )

    return prices


def _province_for(
    provinces: list[tuple[float, str]],
    top: float,
    bottom: float,
) -> str | None:
    """The province a block sits under.

    Unlike an area label, a province label covers several city blocks and is
    centred across them, so it can sit above *or* below any given block. The
    nearest label to the block's own centre is the one it belongs to; taking
    the nearest one above instead leaves the first block on every page without
    a province, which is where a quarter of the rows lost theirs.
    """
    if not provinces:
        return None

    centre = (top + bottom) / 2

    return min(provinces, key=lambda entry: abs(entry[0] - centre))[1]


def _blocks(
    parsed: list[tuple[float, tuple[str, str], dict[str, str]]],
) -> list[list[tuple[float, tuple[str, str], dict[str, str]]]]:
    """Split rows into per-area blocks.

    A block is one area's run of product rows. The boundary is the product
    sequence restarting: the DOE lists the same products in the same order for
    every area, so a product that has already appeared in the current block
    means a new area has begun. That works whether or not the area label
    extracted cleanly, and without assuming how many products a region lists.
    """
    blocks: list[list[tuple[float, tuple[str, str], dict[str, str]]]] = []
    current: list[tuple[float, tuple[str, str], dict[str, str]]] = []
    seen: set[str] = set()

    for top, product, cells in parsed:
        if product[0] in seen and current:
            blocks.append(current)
            current = []
            seen = set()

        seen.add(product[0])
        current.append((top, product, cells))

    if current:
        blocks.append(current)

    return blocks


def _repair_split_range(cells: dict[str, str]) -> dict[str, str]:
    """Move a range's maximum back when it lands in the next column.

    The overall range is the widest cell on the row and its heading is one of
    the narrowest, so its right-hand value can sit marginally closer to the
    next heading's centre — in the Visayas reports "69.95" is 1.1pt nearer
    COMMON than OVERALL RANGE. No column-assignment rule fixes that on
    geometry alone; the values are genuinely interleaved.

    What does fix it is the structure of the field. A range that ends in its
    separator is incomplete, and the token that completes it is the first one
    in the next column. Left alone the range reads "54.30 - 54.30" — a real
    number, wrong, and indistinguishable from a week where prices did not move.
    """
    overall_key = next((key for key in cells if key.upper().startswith("OVERALL")), None)
    common_key = next((key for key in cells if key.upper().startswith("COMMON")), None)

    if overall_key is None or common_key is None:
        return cells

    if not cells[overall_key].strip().endswith(("-", "–")):
        return cells

    parts = cells[common_key].split()

    if not parts or _to_price(parts[0]) is None:
        return cells

    cells[overall_key] = f"{cells[overall_key].strip()} {parts[0]}"
    cells[common_key] = " ".join(parts[1:])

    return cells


def _summary_cell(cells: dict[str, str], prefix: str) -> str:
    """A summary column's value, found by prefix.

    The two layouts name these differently: NCR heads them "OVERALL RANGE" and
    "COMMON PRICE", the Visayas reports "OVERALL RANGE" and bare "COMMON". An
    exact lookup silently returns nothing for the other one, which drops the
    common price for every area in the report while leaving the branded rows
    looking perfectly healthy.
    """
    for name, value in cells.items():
        if name.upper().startswith(prefix):
            return value

    return ""


def _block_prices(
    block: list[tuple[tuple[str, str], dict[str, str]]],
    area: str,
    province: str | None,
    columns: list[tuple[str, float, float]],
) -> list[AreaPrice]:
    """Read every priced cell in one area's block."""
    prices: list[AreaPrice] = []

    for product, raw_cells in block:
        cells = _repair_split_range(raw_cells)
        common = _to_price(_summary_cell(cells, "COMMON"))
        overall_min, overall_max = _range_of(_summary_cell(cells, "OVERALL"))

        for name, _, _ in columns:
            if not is_brand_column(name):
                continue

            numbers = [
                value
                for value in (_to_price(token) for token in cells.get(name, "").split())
                if value is not None
            ]

            if not numbers:
                # A brand this area does not carry. Normal, not a warning — and
                # the cell being empty is exactly why the column had to be found
                # by coordinate rather than by counting numbers along the line.
                continue

            prices.append(
                AreaPrice(
                    area=area,
                    province=province,
                    product=product[0],
                    fuel_code=product[1],
                    brand=name.title(),
                    min_price=min(numbers),
                    max_price=max(numbers),
                )
            )

        if overall_min is not None or overall_max is not None or common is not None:
            prices.append(
                AreaPrice(
                    area=area,
                    province=province,
                    product=product[0],
                    fuel_code=product[1],
                    brand=None,
                    min_price=overall_min,
                    max_price=overall_max,
                    common_price=common,
                )
            )

    return prices


def column_at(centre: float, columns: list[tuple[str, float, float]]) -> str | None:
    """The column a piece of text belongs to: the nearest heading.

    Not a boundary test. Data is routinely wider than the heading above it, in
    both directions and in both layouts — the Visayas overall range prints
    "54.30 - 69.95" across 78pt under a 47pt heading, and its city names
    overflow the CITY/MUNICIPALITY heading on both sides. Any fixed boundary
    that keeps one of those intact cuts through the other; nearest-heading
    handles them symmetrically.
    """
    if not columns:
        return None

    name, _ = min(
        ((name, abs(centre - (left + right) / 2)) for name, left, right in columns),
        key=lambda pair: pair[1],
    )

    return name


def _cells(row: list[dict[str, Any]], columns: list[tuple[str, float, float]]) -> dict[str, str]:
    """Assign each word in a row to a column by its horizontal centre."""
    cells: dict[str, list[str]] = {}

    for word in row:
        name = column_at((word["x0"] + word["x1"]) / 2, columns)

        if name is not None:
            cells.setdefault(name, []).append(word["text"])

    return {name: " ".join(parts) for name, parts in cells.items()}


def _product_of(text: str) -> tuple[str, str] | None:
    upper = " ".join(text.upper().split())

    for product in _PRODUCT_ORDER:
        if upper.startswith(product):
            return product, PRODUCT_CODES[product]

    return None


def _clean_area(text: str) -> str | None:
    """Tidy an area label, rejecting the ones extraction mangled.

    Vertically stacked labels sometimes interleave in the text layer —
    "Muntinlupa City" and "Caloocan City" come back as
    "MCuanlotioncluapna C Citiyty". A label with no vowel-consonant rhythm like
    that is unusable, and guessing at it would file prices under a city that
    does not exist.
    """
    cleaned = " ".join(text.split())

    if len(cleaned) < 3 or len(cleaned) > 60:
        return None

    # The header row's own AREA cell, if it ever reaches here.
    if cleaned.upper() in _NON_BRAND_HEADERS:
        return None

    if re.match(
        r"^(date\s+of\s+monitoring|area|product|source|note|prepared|prevailing)",
        cleaned,
        re.IGNORECASE,
    ):
        return None

    for word in cleaned.split():
        # A capital anywhere but the first letter of a word. Two labels
        # interleaved character by character produce exactly this —
        # "Muntinlupa City" and "Caloocan City" arrive as
        # "MCuanlotioncluapna C Citiyty". All-caps words are left alone, since
        # some regions print area names in capitals.
        if not word.isupper() and any(char.isupper() for char in word[1:]):
            return None

        # No Philippine city or municipality name has a word this long;
        # "Mandaluyong" is 11 and is among the longest. Interleaving roughly
        # doubles the length, which is the cheapest reliable signal there is.
        if len(word) > 14:
            return None

    return cleaned


def _range_of(text: str) -> tuple[float | None, float | None]:
    """Parse an "min - max" overall range cell."""
    parts = re.split(r"\s*[-–]\s*", " ".join(text.split()))
    values = [_to_price(part) for part in parts]
    numbers = [value for value in values if value is not None]

    if not numbers:
        return None, None

    return min(numbers), max(numbers)


# --- alternate extractors -----------------------------------------------------


def extract_with_camelot(path: Path, settings: Settings) -> ExtractedReport:
    """Camelot, both flavours, scored like everything else.

    Kept in the chain although it scores poorly on the layouts seen so far: the
    DOE rules a box per area block and no lines between product rows, so
    lattice merges them. A region that ever rules its rows properly will score
    higher here than the coordinate reader, and the pipeline will pick it.
    """
    import camelot  # noqa: PLC0415

    best = ExtractedReport(None, None, None, None, extractor="camelot")

    for flavour in ("lattice", "stream"):
        try:
            tables = camelot.read_pdf(str(path), pages="all", flavor=flavour)
        except Exception as exc:
            log.debug("camelot %s failed: %s", flavour, exc)
            continue

        report = _from_dataframes([table.df for table in tables], path, f"camelot-{flavour}")

        if report.quality > best.quality:
            best = report

    return best


def extract_with_tabula(path: Path, settings: Settings) -> ExtractedReport:
    """tabula-py. Needs a JVM; disabled by settings where there is none."""
    import tabula  # noqa: PLC0415

    frames = tabula.read_pdf(str(path), pages="all", multiple_tables=True, silent=True)

    return _from_dataframes(frames, path, "tabula")


def _from_dataframes(frames: list[Any], path: Path, name: str) -> ExtractedReport:
    """Turn grid-extractor output into a report, so it can be scored.

    Header metadata still comes from the text layer: neither camelot nor tabula
    returns the title block, and a table with no region cannot be filed.
    """
    import pdfplumber  # noqa: PLC0415

    with pdfplumber.open(str(path)) as pdf:
        text = "\n".join(page.extract_text() or "" for page in pdf.pages)

    region, start, end, monitoring = parse_header(text)

    if start is None or end is None:
        derived = coverage_from_filename(path.name)

        if derived is not None:
            start, end = derived

    report = ExtractedReport(region, start, end, monitoring, extractor=name)

    prices: list[AreaPrice] = []
    current_area: str | None = None

    for frame in frames:
        rows = frame.values.tolist()

        if not rows:
            continue

        header = [str(cell).strip().upper() for cell in rows[0]]
        brand_columns = {
            index: cell
            for index, cell in enumerate(header)
            if cell
            and cell not in _NON_BRAND_HEADERS
            and not cell.startswith("OVERALL")
            and not cell.startswith("COMMON")
        }

        for row in rows[1:]:
            cells = [str(cell).strip() for cell in row]

            if cells and cells[0] and _clean_area(cells[0]):
                current_area = _clean_area(cells[0])

            product = _product_of(cells[1]) if len(cells) > 1 else None

            if product is None or current_area is None:
                continue

            for index, brand in brand_columns.items():
                if index >= len(cells):
                    continue

                numbers = [
                    value
                    for value in (_to_price(token) for token in cells[index].split())
                    if value is not None
                ]

                if numbers:
                    prices.append(
                        AreaPrice(
                            area=current_area,
                            province=None,
                            product=product[0],
                            fuel_code=product[1],
                            brand=brand.title(),
                            min_price=min(numbers),
                            max_price=max(numbers),
                        )
                    )

    report.prices = prices
    report.quality = score(report)

    return report


# --- selection ----------------------------------------------------------------

_EXTRACTORS = {
    "pdfplumber": extract_with_pdfplumber,
    "camelot": extract_with_camelot,
    "tabula": extract_with_tabula,
}


def extract(path: Path, settings: Settings | None = None) -> ExtractedReport:
    """Run the extractors and return the best result.

    Every extractor is tried unless one clears the quality bar outright, and
    the best-scoring result is returned. A failure in one is logged and does
    not stop the others — camelot raises on malformed PDFs and tabula raises
    where there is no JVM, and neither should cost a report that pdfplumber
    reads perfectly well.
    """
    settings = settings or get_settings()
    results: list[ExtractedReport] = []

    # pdfplumber first regardless of configured order: it is the one that
    # handles the layouts actually published, and clearing the bar on the first
    # try avoids paying for a camelot run on every report.
    order = ["pdfplumber"] + [name for name in settings.extractors if name != "pdfplumber"]

    for name in order:
        if name == "tabula" and not settings.enable_tabula:
            continue

        runner = _EXTRACTORS.get(name)

        if runner is None:
            log.warning("Unknown extractor configured: %s", name)
            continue

        try:
            report = runner(path, settings)
        except ImportError as exc:
            log.warning("Extractor %s is not installed: %s", name, exc)
            continue
        except Exception as exc:
            log.warning("Extractor %s failed on %s: %s", name, path.name, exc)
            continue

        log.info(
            "Extractor %s scored %.2f on %s",
            report.extractor,
            report.quality,
            path.name,
        )
        results.append(report)

        if report.quality >= settings.min_extraction_quality:
            break

    if not results:
        raise ExtractionError(f"No extractor could read {path.name}")

    best = max(results, key=lambda report: report.quality)

    if best.quality < settings.min_extraction_quality:
        raise ExtractionError(
            f"Best extraction of {path.name} scored {best.quality:.2f}, "
            f"below the {settings.min_extraction_quality:.2f} threshold "
            f"({best.summary()}). The layout has probably changed."
        )

    log.info("Extracted %s", best.summary())

    return best
