"""Turn a decoded ``batchedDataV2`` payload into records.

Looker does not send rows. It sends parallel, typed column arrays plus a
positional null mask, and a "row" is an index across all of them. Two things
follow, and both have bitten this shape of scraper before:

* The null mask is not optional. A column with nulls carries *fewer* values
  than the row count, because Looker omits them rather than sending null
  entries. Zipping values by position without re-inserting the gaps shifts
  every subsequent value up a row — which produces a complete, plausible,
  entirely wrong dataset. Nothing downstream can detect it.
* Column order is meaningful and column identity is not in the dataset. The
  field ids live elsewhere in the payload and have to be found.

The walk is defensive rather than indexed. Looker moves this nesting between
versions, and a fixed path returns nothing instead of failing.
"""

from __future__ import annotations

import math
from collections.abc import Iterator
from datetime import UTC, date, datetime
from typing import Any

from config import (
    COLUMN_CONTAINER_KEYS,
    COLUMN_VALUE_KEYS,
    DATE_FORMATS,
    FUEL_COLUMNS,
    MAX_PLAUSIBLE_PRICE,
    MIN_PLAUSIBLE_PRICE,
    NULL_INDEX_KEY,
    PH_LAT_RANGE,
    PH_LON_RANGE,
    REQUIRED_FIELDS,
    TABLE_DATASET_KEY,
    is_null_token,
)
from logger import get_logger
from mapper import FieldMapping, _looks_like_field_id

log = get_logger(__name__)


class ParseError(ValueError):
    """A payload was shaped in a way the parser could not use."""


# ---------------------------------------------------------------------------
# Structural walk
# ---------------------------------------------------------------------------


def iter_table_datasets(payload: Any) -> Iterator[tuple[dict[str, Any], list[Any]]]:
    """Yield ``(tableDataset, ancestors)`` for every dataset in a payload.

    Ancestors are returned nearest-first so the field-id search can start close
    to the dataset and widen, rather than picking up ids belonging to a
    different tile on the same page.
    """

    def walk(node: Any, ancestors: list[Any]) -> Iterator[tuple[dict[str, Any], list[Any]]]:
        if isinstance(node, dict):
            dataset = node.get(TABLE_DATASET_KEY)
            if isinstance(dataset, dict):
                yield dataset, [node, *ancestors]

            for key, value in node.items():
                if key == TABLE_DATASET_KEY:
                    continue
                yield from walk(value, [node, *ancestors])

        elif isinstance(node, list):
            for item in node:
                yield from walk(item, ancestors)

    yield from walk(payload, [])


def _column_entries(dataset: dict[str, Any]) -> list[dict[str, Any]]:
    """The per-column wrappers of a tableDataset, in order.

    Looker has used both ``column`` and ``columns`` for this key.
    """
    for key in ("column", "columns"):
        entries = dataset.get(key)
        if isinstance(entries, list):
            return [entry for entry in entries if isinstance(entry, dict)]

    return []


def _typed_values(entry: dict[str, Any]) -> tuple[list[Any], list[int]]:
    """Extract ``(values, null_indexes)`` from one typed column wrapper."""
    for container_key in COLUMN_CONTAINER_KEYS:
        container = entry.get(container_key)
        if not isinstance(container, dict):
            continue

        values: list[Any] = []
        for value_key in COLUMN_VALUE_KEYS:
            candidate = container.get(value_key)
            if isinstance(candidate, list):
                values = list(candidate)
                break

        raw_nulls = container.get(NULL_INDEX_KEY) or []
        null_indexes = [int(index) for index in raw_nulls if isinstance(index, (int, float))]

        return values, null_indexes

    # A column wrapper that is itself a bare list, which older responses use.
    for value in entry.values():
        if isinstance(value, list):
            return list(value), []

    return [], []


def expand_nulls(values: list[Any], null_indexes: list[int], row_count: int) -> list[Any]:
    """Re-insert omitted nulls at their recorded positions.

    ``null_indexes`` are positions in the *final* column. Walking the target
    positions in order and drawing from ``values`` only when the position is not
    masked reconstructs the original alignment.
    """
    if not null_indexes:
        return values

    masked = set(null_indexes)
    expanded: list[Any] = []
    source = iter(values)

    for position in range(row_count):
        if position in masked:
            expanded.append(None)
        else:
            expanded.append(next(source, None))

    return expanded


def _row_count(columns: list[tuple[list[Any], list[int]]]) -> int:
    """How many rows the dataset has.

    A column's true length is its values plus its nulls. Taking the maximum
    across columns tolerates a trailing column that Looker truncated, which it
    does when a tile is still rendering.
    """
    return max((len(values) + len(nulls) for values, nulls in columns), default=0)


def find_field_ids(ancestors: list[Any], expected: int) -> list[str] | None:
    """Locate the ordered field ids for a dataset.

    The ids are not inside ``tableDataset``; they sit in the request echo
    alongside it. Searched nearest-ancestor first and accepted only when the
    count matches the column count — a list of the right shape but the wrong
    length belongs to another tile, and using it would label every column
    wrongly.
    """
    for ancestor in ancestors:
        found = _search_id_list(ancestor, expected, depth=0)
        if found is not None:
            return found

    return None


def _search_id_list(node: Any, expected: int, depth: int) -> list[str] | None:
    """Depth-limited hunt for a list of field ids of length ``expected``."""
    if depth > 6:
        return None

    if isinstance(node, list):
        strings = [item for item in node if isinstance(item, str)]
        if (
            len(strings) == len(node) == expected
            and expected > 0
            and all(_looks_like_field_id(item) for item in strings)
        ):
            return strings

        for item in node:
            found = _search_id_list(item, expected, depth + 1)
            if found is not None:
                return found

        return None

    if isinstance(node, dict):
        # A list of objects each carrying an id, which is how the newer
        # responses echo the request.
        for value in node.values():
            if isinstance(value, list) and len(value) == expected and expected > 0:
                ids = [_id_of(item) for item in value]
                if all(candidate is not None for candidate in ids):
                    return [candidate for candidate in ids if candidate is not None]

        for key, value in node.items():
            if key == TABLE_DATASET_KEY:
                continue
            found = _search_id_list(value, expected, depth + 1)
            if found is not None:
                return found

    return None


def _id_of(item: Any) -> str | None:
    """A field id from an object, if it carries one."""
    if _looks_like_field_id(item):
        return item  # type: ignore[return-value]

    if isinstance(item, dict):
        for key in ("name", "id", "fieldId", "lookerFieldId"):
            candidate = item.get(key)
            if _looks_like_field_id(candidate):
                return candidate

    return None


# ---------------------------------------------------------------------------
# Value normalisation
# ---------------------------------------------------------------------------


def parse_price(value: Any) -> float | None:
    """A price in pesos per litre, or ``None`` if it is not one.

    The bounds reject the two things that have actually appeared in a price
    column: a sentinel for "no data", and a volume or station count landing
    here after a report edit reordered the fields.
    """
    if is_null_token(value):
        return None

    if isinstance(value, str):
        cleaned = value.replace(",", "").replace("₱", "").replace("P", "").strip()
    else:
        cleaned = value

    try:
        price = float(cleaned)
    except (TypeError, ValueError):
        return None

    if not math.isfinite(price):  # NaN or ±inf
        return None

    if not MIN_PLAUSIBLE_PRICE <= price <= MAX_PLAUSIBLE_PRICE:
        return None

    return round(price, 4)


def parse_date(value: Any) -> date | None:
    """A calendar date from whatever the report author configured."""
    if is_null_token(value):
        return None

    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value

    # Epoch values. Looker sends seconds for date fields and milliseconds for
    # datetimes; the threshold separates them without ambiguity for any date
    # this century.
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        seconds = value / 1000 if value > 10_000_000_000 else value
        try:
            return datetime.fromtimestamp(seconds, tz=UTC).date()
        except (OverflowError, OSError, ValueError):
            return None

    text = str(value).strip()
    if not text:
        return None

    for fmt in DATE_FORMATS:
        try:
            # Deliberately naive: the DOE publishes a calendar date, not an
            # instant. Attaching a timezone here would shift the date by a day
            # for anything published near midnight Manila time.
            return datetime.strptime(text, fmt).date()  # noqa: DTZ007
        except ValueError:
            continue

    # A numeric string that is really an epoch.
    if text.isdigit() and len(text) >= 10:
        try:
            number = int(text)
            seconds = number / 1000 if number > 10_000_000_000 else number
            return datetime.fromtimestamp(seconds, tz=UTC).date()
        except (OverflowError, OSError, ValueError):
            return None

    return None


def parse_coordinate(value: Any, *, is_latitude: bool) -> float | None:
    """A Philippine coordinate, or ``None``.

    Out-of-range values are dropped rather than stored. A station at (0, 0) —
    what an unparsed coordinate becomes — puts a pin in the Gulf of Guinea and
    breaks every "nearest station" query that touches it.
    """
    if is_null_token(value):
        return None

    try:
        number = float(str(value).strip())
    except (TypeError, ValueError):
        return None

    low, high = PH_LAT_RANGE if is_latitude else PH_LON_RANGE

    return round(number, 8) if low <= number <= high else None


def parse_text(value: Any, *, limit: int = 400) -> str | None:
    """A trimmed string, or ``None`` for the feed's many ways of saying nothing."""
    if is_null_token(value):
        return None

    text = " ".join(str(value).split())

    return text[:limit] if text else None


# ---------------------------------------------------------------------------
# Records
# ---------------------------------------------------------------------------


def normalize_record(raw: dict[str, Any]) -> dict[str, Any] | None:
    """Coerce one mapped row into storable values, or drop it.

    Returns ``None`` when the row lacks what identifies it. A price with no
    station and no date is not a partial record; it is noise.
    """
    record: dict[str, Any] = {
        "station": parse_text(raw.get("station"), limit=200),
        "company": parse_text(raw.get("company"), limit=120),
        "region": parse_text(raw.get("region"), limit=120),
        "province": parse_text(raw.get("province"), limit=120),
        "city": parse_text(raw.get("city"), limit=120),
        "barangay": parse_text(raw.get("barangay"), limit=120),
        "address": parse_text(raw.get("address")),
        "price_date": parse_date(raw.get("price_date")),
        "latitude": parse_coordinate(raw.get("latitude"), is_latitude=True),
        "longitude": parse_coordinate(raw.get("longitude"), is_latitude=False),
    }

    for column in FUEL_COLUMNS:
        record[column] = parse_price(raw.get(column))

    # A station with no company still identifies a site; fall back so the row
    # survives, since the DOE omits the brand for independents.
    if record["company"] is None and record["station"]:
        record["company"] = "Independent"

    for required in REQUIRED_FIELDS:
        if record.get(required) is None:
            return None

    if all(record[column] is None for column in FUEL_COLUMNS):
        return None

    return record


def parse_dataset(
    dataset: dict[str, Any],
    ancestors: list[Any],
    mapping: FieldMapping,
) -> list[dict[str, Any]]:
    """Convert one tableDataset into normalised records."""
    entries = _column_entries(dataset)
    if not entries:
        return []

    columns = [_typed_values(entry) for entry in entries]
    row_count = _row_count(columns)

    if row_count == 0:
        return []

    field_ids = find_field_ids(ancestors, expected=len(columns))

    if field_ids is None:
        # Without ids the columns cannot be attributed. Content could identify
        # the dimensions, but never which grade a price column is — and a
        # dataset whose RON 91 and RON 95 might be swapped is worse than no
        # dataset, because it looks fine.
        raise ParseError(
            f"No field ids for a {len(columns)}-column dataset; cannot attribute columns"
        )

    expanded = [expand_nulls(values, nulls, row_count) for values, nulls in columns]

    records: list[dict[str, Any]] = []

    for row_index in range(row_count):
        raw: dict[str, Any] = {}

        for column_index, field_id in enumerate(field_ids):
            column_name = mapping.column_for(field_id)
            if column_name is None:
                continue

            values = expanded[column_index]
            raw[column_name] = values[row_index] if row_index < len(values) else None

        record = normalize_record(raw)
        if record is not None:
            records.append(record)

    return records


def parse_payloads(
    payloads: list[Any], mapping: FieldMapping
) -> tuple[list[dict[str, Any]], list[str]]:
    """Parse every dataset in every payload.

    Returns ``(records, errors)``. One unusable dataset does not stop the rest:
    a dashboard page carries several tiles and the summary tiles routinely have
    a shape we cannot use, which must not cost us the detail table.
    """
    records: list[dict[str, Any]] = []
    errors: list[str] = []
    dataset_count = 0

    for payload_index, payload in enumerate(payloads):
        for dataset, ancestors in iter_table_datasets(payload):
            dataset_count += 1
            try:
                parsed = parse_dataset(dataset, ancestors, mapping)
            except ParseError as exc:
                errors.append(f"payload {payload_index}: {exc}")
                continue
            except Exception as exc:
                errors.append(f"payload {payload_index}: unexpected {type(exc).__name__}: {exc}")
                log.exception("Dataset failed to parse")
                continue

            records.extend(parsed)

    log.info(
        "Parsed %d records from %d datasets across %d payloads",
        len(records),
        dataset_count,
        len(payloads),
        extra={"errors": len(errors)},
    )

    return deduplicate(records), errors


def deduplicate(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Collapse rows describing the same station and date.

    The dashboard serves the same station from more than one tile, and each
    tile may carry a different subset of the grades. Merging field by field
    keeps whichever tile knew the price; taking the last row would discard it.
    """
    merged: dict[tuple[Any, ...], dict[str, Any]] = {}

    for record in records:
        key = (
            (record.get("company") or "").casefold(),
            (record.get("station") or "").casefold(),
            (record.get("city") or "").casefold(),
            (record.get("barangay") or "").casefold(),
            record.get("price_date"),
        )

        if key not in merged:
            merged[key] = dict(record)
            continue

        target = merged[key]
        for column, value in record.items():
            if value is not None and target.get(column) is None:
                target[column] = value

    return list(merged.values())
