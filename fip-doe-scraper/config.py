"""What is true about the DOE Looker Studio dashboard, wherever we run.

Nothing here is secret and nothing here changes per environment — see
:mod:`settings` for that. This module holds the protocol details of the Looker
Studio backend and the vocabulary needed to turn its labels into our columns.

Two things this module is careful *not* to do:

* It does not hardcode internal field ids (``qt_fgaojmiemc`` and friends).
  Those are regenerated whenever the report is edited, so a scraper pinned to
  them breaks silently — it keeps running and writes nothing. The ids are
  resolved at run time from ``getSchema``; what lives here is how to recognise
  a *human* label, which is stable because it is what the DOE publishes.
* It does not describe the HTML. There is no selector, no xpath and no table
  layout anywhere in this project.
"""

from __future__ import annotations

import re
from typing import Final

# ---------------------------------------------------------------------------
# Looker Studio protocol
# ---------------------------------------------------------------------------

#: Every JSON body from the Looker backend is prefixed with this anti-JSON-
#: hijacking guard. It must be stripped before the body will decode.
JSON_HIJACK_PREFIX: Final[str] = ")]}'"

#: Substring that identifies a data response. Looker versions the path
#: (``batchedDataV2``, previously ``batchedData``), so we match on the stem.
DATA_ENDPOINT_MARKER: Final[str] = "batchedDataV2"

#: The schema response, which carries internal-id → label for every field.
SCHEMA_ENDPOINT_MARKER: Final[str] = "getSchema"

#: The report definition. Useful for the page/tile inventory when debugging a
#: run that captured fewer datasets than expected.
REPORT_ENDPOINT_MARKER: Final[str] = "getReport"

#: All endpoints worth intercepting.
INTERCEPT_MARKERS: Final[tuple[str, ...]] = (
    DATA_ENDPOINT_MARKER,
    SCHEMA_ENDPOINT_MARKER,
    REPORT_ENDPOINT_MARKER,
)

#: Keys walked to reach the column arrays. Looker nests these consistently, but
#: wraps them in singleton lists at two levels, which is why the parser walks
#: rather than indexes.
DATA_RESPONSE_KEY: Final[str] = "dataResponse"
DATA_SUBSET_KEY: Final[str] = "dataSubset"
TABLE_DATASET_KEY: Final[str] = "tableDataset"

#: Within a tableDataset, the per-type column arrays. Looker splits a row into
#: parallel typed arrays rather than emitting records, so a "row" is an index
#: across all of these.
COLUMN_CONTAINER_KEYS: Final[tuple[str, ...]] = (
    "stringColumn",
    "doubleColumn",
    "longColumn",
    "dateColumn",
    "datetimeColumn",
    "boolColumn",
    "bytesColumn",
)

#: Inside each typed column, the array holding the values.
COLUMN_VALUE_KEYS: Final[tuple[str, ...]] = ("values", "value", "nanos")

#: Parallel to the values: which entries are null. Looker sends a positional
#: null mask rather than null entries, so a column with nulls has *fewer*
#: values than the row count and pairing by position without the mask silently
#: shifts every subsequent value into the wrong row.
NULL_INDEX_KEY: Final[str] = "nullIndex"


# ---------------------------------------------------------------------------
# Label vocabulary
# ---------------------------------------------------------------------------

#: Canonical price columns, in the order they appear in ``fuel_price_history``.
FUEL_COLUMNS: Final[tuple[str, ...]] = (
    "ron91",
    "ron95",
    "ron97",
    "ron100",
    "diesel",
    "diesel_plus",
)

#: Ordered label patterns for the price columns.
#:
#: Order matters. ``diesel_plus`` is tested before ``diesel`` because "Diesel
#: Plus" contains "Diesel" and would otherwise be swallowed by it. Likewise
#: RON 100 before RON 10 — no such column exists today, but the DOE has added
#: grades before and a partial match would put the new one in the wrong column
#: rather than failing loudly.
FUEL_LABEL_PATTERNS: Final[tuple[tuple[str, re.Pattern[str]], ...]] = (
    (
        # Matched in both orders. The DOE's own labels put the qualifier after
        # the grade ("Diesel Plus"), but retailer names put it first ("Euro 5
        # Diesel"), and a one-directional pattern silently files those as plain
        # diesel — mixing two different products into one column.
        "diesel_plus",
        re.compile(
            r"(?:diesel\s*(?:plus|premium|euro\s*\d|max|blaze|xtra|extra)"
            r"|(?:premium|euro\s*\d|max|blaze|xtra|extra)\s*diesel)",
            re.IGNORECASE,
        ),
    ),
    ("diesel", re.compile(r"\bdiesel\b", re.IGNORECASE)),
    ("ron100", re.compile(r"(?:ron|rON)?\s*100\b", re.IGNORECASE)),
    ("ron97", re.compile(r"(?:ron)?\s*97\b", re.IGNORECASE)),
    ("ron95", re.compile(r"(?:ron)?\s*95\b", re.IGNORECASE)),
    ("ron91", re.compile(r"(?:ron)?\s*91\b", re.IGNORECASE)),
)

#: Label patterns for the descriptive (non-price) columns.
DIMENSION_LABEL_PATTERNS: Final[tuple[tuple[str, re.Pattern[str]], ...]] = (
    ("latitude", re.compile(r"^\s*lat(itude)?\s*$", re.IGNORECASE)),
    ("longitude", re.compile(r"^\s*(lon|lng|long(itude)?)\s*$", re.IGNORECASE)),
    (
        "price_date",
        re.compile(r"\b(timestamp|date|as\s*of|effectiv|updated|week)\b", re.IGNORECASE),
    ),
    (
        # No trailing \b: "compan" is a stem, and anchoring it would fail on
        # every real spelling of the word.
        "company",
        re.compile(
            r"\b(compan(?:y|ies)|brand|oil\s*(?:firm|compan\w*)|retailer|player)",
            re.IGNORECASE,
        ),
    ),
    (
        "station",
        re.compile(r"\b(gas\s*station|station|outlet|site|branch)\b", re.IGNORECASE),
    ),
    ("barangay", re.compile(r"\b(barangay|brgy)\b", re.IGNORECASE)),
    ("city", re.compile(r"\b(city|municipalit(?:y|ies))", re.IGNORECASE)),
    ("province", re.compile(r"\bprovince\b", re.IGNORECASE)),
    ("region", re.compile(r"\bregion\b", re.IGNORECASE)),
    ("address", re.compile(r"\b(address|location|street)\b", re.IGNORECASE)),
)

#: A record needs these before it is worth storing. Everything else is optional
#: — the DOE's coverage of coordinates and barangay is patchy, and dropping an
#: otherwise good price because it lacks a latitude would lose most of Mindanao.
REQUIRED_FIELDS: Final[tuple[str, ...]] = ("station", "price_date")

#: Sanity bounds in pesos per litre. These reject the two failure modes seen in
#: practice: a column that is actually a volume or a count landing in a price
#: field, and Looker emitting a sentinel for "no data".
MIN_PLAUSIBLE_PRICE: Final[float] = 5.0
MAX_PLAUSIBLE_PRICE: Final[float] = 500.0

#: Coordinate bounds for the Philippines, generously drawn. A station outside
#: these is a parse error, not a station.
PH_LAT_RANGE: Final[tuple[float, float]] = (4.0, 21.5)
PH_LON_RANGE: Final[tuple[float, float]] = (116.0, 127.0)

#: Looker returns dates in whichever format the report author configured.
DATE_FORMATS: Final[tuple[str, ...]] = (
    "%Y%m%d",
    "%Y-%m-%d",
    "%Y/%m/%d",
    "%d/%m/%Y",
    "%m/%d/%Y",
    "%Y%m%d%H",
    "%Y%m%d%H%M%S",
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%dT%H:%M:%S",
)

#: Values Looker uses for "nothing here", which must not become the string
#: "null" in a company column.
NULL_TOKENS: Final[frozenset[str]] = frozenset(
    {"", "-", "--", "n/a", "na", "null", "none", "nil", "#n/a", "no data", "tbd"}
)


def normalize_label(label: str) -> str:
    """Collapse a Looker label to a comparable form.

    Report authors are inconsistent about case, spacing and punctuation
    (``Gas Station``, ``gas_station``, ``GAS STATION``), and the label is the
    only stable handle we have on a field.
    """
    # "+" carries meaning — "Diesel+" is a different product from "Diesel" —
    # and stripping it as punctuation would file the two into one column.
    spelled = label.strip().lower().replace("+", " plus ")
    cleaned = re.sub(r"[^a-z0-9]+", " ", spelled)
    return re.sub(r"\s+", " ", cleaned).strip()


def is_null_token(value: object) -> bool:
    """Whether a decoded value should be treated as missing."""
    if value is None:
        return True
    if isinstance(value, str):
        return value.strip().lower() in NULL_TOKENS
    return False
