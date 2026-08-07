"""Dynamic field mapping.

Looker Studio names fields with generated ids — ``qt_fgaojmiemc``,
``qt_85e4fhiemc`` — that are regenerated every time the report is edited. A
scraper that hardcodes them does not fail when the DOE republishes; it keeps
running and writes nothing, which is worse.

So nothing is hardcoded. ``getSchema`` gives id → human label at run time, and
this module turns the label into one of our columns. The labels are what the
DOE publishes to the public, which makes them the stable half of the pair.

Two levels of indirection:

    qt_85e4fhiemc  ──getSchema──▶  "Gas Station"  ──patterns──▶  station
    (per report)                   (public)                      (ours)
"""

from __future__ import annotations

import re
from collections.abc import Iterator
from dataclasses import dataclass, field
from typing import Any

from config import (
    DIMENSION_LABEL_PATTERNS,
    FUEL_COLUMNS,
    FUEL_LABEL_PATTERNS,
    normalize_label,
)
from logger import get_logger

log = get_logger(__name__)

#: Keys that hold a field's internal id, most specific first.
_ID_KEYS: tuple[str, ...] = (
    "name",
    "id",
    "fieldId",
    "lookerFieldId",
    "dataSourceFieldId",
)

#: Keys that hold a field's human label.
_LABEL_KEYS: tuple[str, ...] = ("label", "displayName", "alias", "title", "caption")

#: What a generated field id looks like. Deliberately broad — Looker has used
#: `qt_`, `_n_` and bare hashes across versions — but tight enough not to match
#: a human label.
_FIELD_ID_PATTERN = re.compile(r"^(?:qt_|_n_|calc_)?[a-z0-9_]{6,64}$")


@dataclass
class FieldMapping:
    """Resolved id → label → column for one report."""

    #: internal id → human label, straight from getSchema.
    labels: dict[str, str] = field(default_factory=dict)
    #: internal id → our column name, for ids we recognised.
    columns: dict[str, str] = field(default_factory=dict)
    #: labels seen but not matched to any column, for the run log.
    unmapped: list[str] = field(default_factory=list)

    def column_for(self, field_id: str) -> str | None:
        """Our column for an internal id, or ``None`` if unrecognised."""
        return self.columns.get(field_id)

    def label_for(self, field_id: str) -> str:
        """The human label for an internal id, falling back to the id."""
        return self.labels.get(field_id, field_id)

    @property
    def is_usable(self) -> bool:
        """Whether enough was resolved to produce a record.

        A mapping that found a station but no price column would import rows of
        nothing, and a mapping with prices but no station cannot attribute them.
        """
        mapped = set(self.columns.values())
        return "station" in mapped and bool(mapped & set(FUEL_COLUMNS))

    def summary(self) -> str:
        mapped = sorted(set(self.columns.values()))
        return f"mapped={mapped} unmapped={len(self.unmapped)}"


def _looks_like_field_id(value: Any) -> bool:
    """Whether a value could be a Looker field id."""
    return isinstance(value, str) and bool(_FIELD_ID_PATTERN.match(value))


def _walk(node: Any) -> Iterator[dict[str, Any]]:
    """Yield every dict in a decoded payload, depth first.

    The schema is walked rather than indexed because Looker nests it differently
    between report versions — under ``datasourceSchema``, ``dataset.fields`` or
    a bare ``fields`` — and a fixed path silently yields nothing when it moves.
    """
    if isinstance(node, dict):
        yield node
        for value in node.values():
            yield from _walk(value)
    elif isinstance(node, list):
        for item in node:
            yield from _walk(item)


def extract_labels(payload: Any) -> dict[str, str]:
    """Pull internal id → label pairs out of a ``getSchema`` payload."""
    labels: dict[str, str] = {}

    for node in _walk(payload):
        field_id: str | None = None
        for key in _ID_KEYS:
            candidate = node.get(key)
            if _looks_like_field_id(candidate):
                field_id = candidate
                break

        if field_id is None:
            continue

        label: str | None = None
        for key in _LABEL_KEYS:
            candidate = node.get(key)
            # The label must differ from the id, or a node whose `name` is the
            # id and whose only other string is the same id maps onto itself.
            if isinstance(candidate, str) and candidate.strip() and candidate != field_id:
                label = candidate.strip()
                break

        if label is None:
            continue

        # First writer wins: the schema lists a field once, but getReport
        # repeats it per tile, sometimes with a tile-local alias.
        labels.setdefault(field_id, label)

    return labels


def classify_label(label: str) -> str | None:
    """Map one human label to one of our columns.

    Fuel patterns are tried before dimensions: a column labelled "Diesel Price"
    matches the price pattern and would otherwise be caught by the address
    pattern's ``price``-adjacent wording in some report revisions.
    """
    normalized = normalize_label(label)

    if not normalized:
        return None

    for column, pattern in FUEL_LABEL_PATTERNS:
        if pattern.search(normalized):
            return column

    for column, pattern in DIMENSION_LABEL_PATTERNS:
        if pattern.search(normalized):
            return column

    return None


def build_mapping(schema_payloads: list[Any]) -> FieldMapping:
    """Build the mapping from every ``getSchema`` response seen in a run.

    Payloads are merged because Looker requests a schema per data source, and a
    dashboard with a separate source for its map tile returns two.
    """
    mapping = FieldMapping()

    for payload in schema_payloads:
        for field_id, label in extract_labels(payload).items():
            mapping.labels.setdefault(field_id, label)

    taken: dict[str, str] = {}

    for field_id, label in mapping.labels.items():
        column = classify_label(label)

        if column is None:
            mapping.unmapped.append(label)
            continue

        # Two ids claiming one column happens when a report carries both a
        # "Diesel" metric and a "Diesel (avg)" calculated field. Keep the first
        # and record the loser, rather than letting arbitrary dict order decide
        # which one the prices come from.
        if column in taken:
            mapping.unmapped.append(f"{label} (duplicate of {taken[column]})")
            continue

        taken[column] = label
        mapping.columns[field_id] = column

    log.info("Field mapping built", extra={"mapping": mapping.summary()})

    if mapping.unmapped:
        log.debug("Unmapped labels: %s", ", ".join(sorted(set(mapping.unmapped))[:40]))

    return mapping


def infer_mapping_from_labels(labels: dict[str, str]) -> FieldMapping:
    """Build a mapping from an id → label dict directly.

    Used by tests and by the replay path, where the labels are already known and
    there is no schema payload to walk.
    """
    mapping = FieldMapping(labels=dict(labels))
    taken: dict[str, str] = {}

    for field_id, label in labels.items():
        column = classify_label(label)
        if column is None:
            mapping.unmapped.append(label)
        elif column in taken:
            mapping.unmapped.append(f"{label} (duplicate of {taken[column]})")
        else:
            taken[column] = label
            mapping.columns[field_id] = column

    return mapping
