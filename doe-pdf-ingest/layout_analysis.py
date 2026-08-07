"""Describe what is actually on a PDF page, before anyone writes a parser for it.

Every extraction bug this project has had was a guess about layout that looked
right: a header row read as data, a stale area label stealing the next area's
prices, a row split in two by a bucketing constant, phantom brand columns
invented from interleaved headings. None of them were visible in the extracted
text. All of them were obvious in the coordinates.

So this writes coordinates out and draws them, and states nothing about how the
page *should* be read:

    JSON  every character and word with its bounding box, the reading order the
          usual sort produces, the row bands and column candidates found, and
          the evidence for each
    CSV   the same characters and words, for a spreadsheet or a diff
    SVG   the page drawn to scale with the boxes, bands and candidate column
          boundaries on top

The column candidates are **candidates**. They come from gaps in the horizontal
ink projection, which is a description of where the page has no text — not a
claim about what the columns mean. Read the overlay before believing them.

    python layout_analysis.py report.pdf --page 1 --out ./analysis
    python layout_analysis.py report.pdf --all-pages --out ./analysis
"""

from __future__ import annotations

import argparse
import csv
import json
import statistics
import sys
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

import pdfplumber

#: Words closer together vertically than this share a row band. Derived from
#: the page's own median character height rather than fixed: a constant is what
#: split rows in two when the DOE changed font size between layouts.
ROW_BAND_FRACTION = 0.6

#: A horizontal run of blank page at least this wide, relative to the median
#: character width, is a column boundary candidate.
GAP_WIDTH_FACTOR = 1.8


@dataclass
class Box:
    x0: float
    x1: float
    top: float
    bottom: float

    @property
    def width(self) -> float:
        return self.x1 - self.x0

    @property
    def height(self) -> float:
        return self.bottom - self.top


@dataclass
class WordRecord:
    text: str
    box: Box
    #: Index in the reading order this tool computed, so a disagreement with
    #: the parser's own order is visible rather than inferred.
    reading_order: int
    row_band: int
    #: Which column candidate the word's midpoint falls in, or -1.
    column: int


@dataclass
class ColumnCandidate:
    index: int
    x0: float
    x1: float
    #: The blank run to the left of this column, which is why it starts here.
    gap_before: float
    word_count: int
    sample_text: list[str] = field(default_factory=list)


@dataclass
class RowBand:
    index: int
    top: float
    bottom: float
    word_count: int
    text: str


@dataclass
class PageAnalysis:
    page_number: int
    width: float
    height: float
    char_count: int
    word_count: int
    median_char_width: float
    median_char_height: float
    rotation: int
    #: True when the page carries no extractable text at all — the one finding
    #: that makes every other number here meaningless.
    is_image_only: bool
    #: A full-page image with no vector drawing under it. Any text on such a
    #: page came from OCR, so its coordinates are estimates of where a
    #: recogniser thought glyphs were, not where a typesetter put them. Column
    #: geometry cannot be trusted, and no amount of parser work makes it
    #: trustworthy — the finding that separates a layout problem from a
    #: document-type one.
    looks_scanned: bool
    full_page_images: int
    vector_objects: int
    columns: list[ColumnCandidate]
    rows: list[RowBand]
    words: list[WordRecord]

    def to_json(self) -> dict[str, Any]:
        return {
            "page_number": self.page_number,
            "width": self.width,
            "height": self.height,
            "rotation": self.rotation,
            "char_count": self.char_count,
            "word_count": self.word_count,
            "median_char_width": round(self.median_char_width, 2),
            "median_char_height": round(self.median_char_height, 2),
            "is_image_only": self.is_image_only,
            "looks_scanned": self.looks_scanned,
            "full_page_images": self.full_page_images,
            "vector_objects": self.vector_objects,
            "column_candidates": [asdict(column) for column in self.columns],
            "row_bands": [asdict(row) for row in self.rows],
            "words": [
                {
                    "text": word.text,
                    "reading_order": word.reading_order,
                    "row_band": word.row_band,
                    "column": word.column,
                    **asdict(word.box),
                }
                for word in self.words
            ],
        }


def _median(values: list[float], fallback: float) -> float:
    return statistics.median(values) if values else fallback


def _row_bands(
    words: list[dict[str, Any]], line_height: float
) -> list[tuple[float, float, float]]:
    """Group words into bands by vertical midpoint.

    Two failure modes to avoid, both of which this project has shipped:

    `int(top // n)` bucketing splits one row across two buckets whenever its
    baseline straddles a boundary — so bands are found, not imposed.

    Comparing a word against the *bottom* of the band before it chains every
    row on the page into one: the band's bottom has already grown by a line
    height, so the next row's top sits flush against it and merges. Each band
    therefore keeps a fixed anchor — the midpoint of its first word — and a
    word joins only if its own midpoint is within half a line of that.
    """
    if not words:
        return []

    tolerance = max(line_height * ROW_BAND_FRACTION, 0.5)

    bands: list[list[float]] = []  # [top, bottom, anchor]

    for word in sorted(words, key=lambda w: (float(w["top"]) + float(w["bottom"])) / 2):
        top, bottom = float(word["top"]), float(word["bottom"])
        midpoint = (top + bottom) / 2

        if bands and abs(midpoint - bands[-1][2]) <= tolerance:
            bands[-1][0] = min(bands[-1][0], top)
            bands[-1][1] = max(bands[-1][1], bottom)
        else:
            bands.append([top, bottom, midpoint])

    return [(band[0], band[1], band[2]) for band in bands]


def _column_candidates(
    words: list[dict[str, Any]], page_width: float, char_width: float
) -> list[tuple[float, float, float]]:
    """Find vertical blank runs wide enough to be column boundaries.

    Projects every word onto the x axis and looks for gaps. This says where the
    page has no ink; whether a gap separates two columns or merely two words in
    a sparse row is a judgement the overlay is for.
    """
    if not words:
        return []

    occupied = [(float(word["x0"]), float(word["x1"])) for word in words]
    occupied.sort()

    merged: list[list[float]] = []

    for x0, x1 in occupied:
        if merged and x0 <= merged[-1][1]:
            merged[-1][1] = max(merged[-1][1], x1)
        else:
            merged.append([x0, x1])

    minimum_gap = char_width * GAP_WIDTH_FACTOR
    spans: list[tuple[float, float, float]] = []
    gap_before = merged[0][0]

    for index, (x0, x1) in enumerate(merged):
        if index > 0:
            gap_before = x0 - merged[index - 1][1]

        if index == 0 or gap_before >= minimum_gap:
            spans.append((x0, x1, gap_before))
        else:
            previous = spans[-1]
            spans[-1] = (previous[0], max(previous[1], x1), previous[2])

    return spans


def analyse_page(page: pdfplumber.page.Page) -> PageAnalysis:
    chars = page.chars

    page_width, page_height = float(page.width), float(page.height)
    full_page_images = sum(
        1
        for image in page.images
        if float(image["width"]) >= page_width * 0.9
        and float(image["height"]) >= page_height * 0.9
    )
    vector_objects = len(page.lines) + len(page.rects) + len(page.curves)
    # use_text_flow=True keeps words the PDF's own text order joined, which is
    # what stops "BANGUED CITY" arriving as "BANGUEDC ITY" on the layouts that
    # position each glyph individually.
    words = page.extract_words(use_text_flow=True, keep_blank_chars=False)

    char_widths = [float(c["x1"]) - float(c["x0"]) for c in chars]
    char_heights = [float(c["bottom"]) - float(c["top"]) for c in chars]

    median_width = _median(char_widths, 5.0)
    median_height = _median(char_heights, 10.0)

    bands = _row_bands(words, median_height)
    spans = _column_candidates(words, float(page.width), median_width)

    columns = [
        ColumnCandidate(index=index, x0=round(x0, 2), x1=round(x1, 2), gap_before=round(gap, 2),
                        word_count=0)
        for index, (x0, x1, gap) in enumerate(spans)
    ]

    rows = [
        RowBand(index=index, top=round(top, 2), bottom=round(bottom, 2), word_count=0, text="")
        for index, (top, bottom, _) in enumerate(bands)
    ]
    anchors = [anchor for _, _, anchor in bands]

    records: list[WordRecord] = []

    for word in words:
        box = Box(
            x0=round(float(word["x0"]), 2),
            x1=round(float(word["x1"]), 2),
            top=round(float(word["top"]), 2),
            bottom=round(float(word["bottom"]), 2),
        )

        midpoint_x = (box.x0 + box.x1) / 2
        midpoint_y = (box.top + box.bottom) / 2

        # Nearest anchor rather than first containing band: bands can overlap
        # where one row carries a taller glyph, and "first match wins" would
        # then put a word in the row above the one it is printed on.
        band_index = (
            min(range(len(anchors)), key=lambda i: abs(anchors[i] - midpoint_y))
            if anchors
            else -1
        )
        column_index = next(
            (column.index for column in columns if column.x0 <= midpoint_x <= column.x1),
            -1,
        )

        records.append(
            WordRecord(text=word["text"], box=box, reading_order=0,
                       row_band=band_index, column=column_index)
        )

    # Reading order: down the page, then across. Stated explicitly so it can be
    # compared against what the parser assumes rather than guessed at.
    records.sort(key=lambda record: (record.row_band, record.box.x0))

    for order, record in enumerate(records):
        record.reading_order = order

    for record in records:
        if record.column >= 0:
            columns[record.column].word_count += 1

            if len(columns[record.column].sample_text) < 6:
                columns[record.column].sample_text.append(record.text)

        if record.row_band >= 0:
            rows[record.row_band].word_count += 1

    for row in rows:
        row.text = " ".join(
            record.text for record in records if record.row_band == row.index
        )[:300]

    return PageAnalysis(
        page_number=page.page_number,
        width=round(float(page.width), 2),
        height=round(float(page.height), 2),
        rotation=int(page.rotation or 0),
        char_count=len(chars),
        word_count=len(words),
        median_char_width=median_width,
        median_char_height=median_height,
        # The distinction that decides whether a layout needs a parser or an
        # OCR pipeline, and the first thing to check when everything else on
        # the page reads as empty.
        is_image_only=len(chars) == 0,
        looks_scanned=full_page_images > 0 and vector_objects == 0,
        full_page_images=full_page_images,
        vector_objects=vector_objects,
        columns=columns,
        rows=rows,
        words=records,
    )


def write_csvs(analysis: PageAnalysis, page: pdfplumber.page.Page, stem: Path) -> list[Path]:
    written = []

    words_path = stem.with_name(f"{stem.name}-words.csv")

    with words_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(
            ["reading_order", "row_band", "column", "text", "x0", "x1", "top", "bottom"]
        )

        for word in analysis.words:
            writer.writerow([
                word.reading_order, word.row_band, word.column, word.text,
                word.box.x0, word.box.x1, word.box.top, word.box.bottom,
            ])

    written.append(words_path)

    chars_path = stem.with_name(f"{stem.name}-chars.csv")

    with chars_path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["text", "x0", "x1", "top", "bottom", "size", "fontname", "upright"])

        for char in page.chars:
            writer.writerow([
                char.get("text", ""),
                round(float(char["x0"]), 2), round(float(char["x1"]), 2),
                round(float(char["top"]), 2), round(float(char["bottom"]), 2),
                round(float(char.get("size", 0)), 2),
                char.get("fontname", ""),
                char.get("upright", ""),
            ])

    written.append(chars_path)

    return written


def _escape(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def write_svg(analysis: PageAnalysis, stem: Path, *, labels: bool) -> Path:
    """Draw the page to scale with the geometry on top.

    Rendered at PDF point size so every coordinate in the JSON and CSV can be
    located on the picture without arithmetic.
    """
    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{analysis.width}" '
        f'height="{analysis.height}" viewBox="0 0 {analysis.width} {analysis.height}">',
        '<rect width="100%" height="100%" fill="white"/>',
        "<style>"
        ".band{fill:#0ea5e9;fill-opacity:.06}"
        ".band-alt{fill:#0ea5e9;fill-opacity:.12}"
        ".col{fill:#f59e0b;fill-opacity:.08}"
        ".colline{stroke:#b45309;stroke-width:.6;stroke-dasharray:3 2}"
        ".word{fill:none;stroke:#059669;stroke-width:.35}"
        ".txt{font-family:monospace;font-size:3px;fill:#111}"
        ".ord{font-family:monospace;font-size:2.4px;fill:#b91c1c}"
        ".legend{font-family:sans-serif;font-size:7px;fill:#334155}"
        "</style>",
    ]

    for column in analysis.columns:
        parts.append(
            f'<rect class="col" x="{column.x0}" y="0" '
            f'width="{max(column.x1 - column.x0, 0.5)}" height="{analysis.height}"/>'
        )
        parts.append(
            f'<line class="colline" x1="{column.x0}" y1="0" '
            f'x2="{column.x0}" y2="{analysis.height}"/>'
        )

    for row in analysis.rows:
        css = "band-alt" if row.index % 2 else "band"
        parts.append(
            f'<rect class="{css}" x="0" y="{row.top}" width="{analysis.width}" '
            f'height="{max(row.bottom - row.top, 0.5)}"/>'
        )

    for word in analysis.words:
        parts.append(
            f'<rect class="word" x="{word.box.x0}" y="{word.box.top}" '
            f'width="{max(word.box.width, 0.5)}" height="{max(word.box.height, 0.5)}"/>'
        )

        if labels:
            parts.append(
                f'<text class="txt" x="{word.box.x0}" y="{word.box.bottom - 0.6}">'
                f"{_escape(word.text)}</text>"
            )
            parts.append(
                f'<text class="ord" x="{word.box.x0}" y="{word.box.top - 0.4}">'
                f"{word.reading_order}</text>"
            )

    parts.append(
        f'<text class="legend" x="4" y="{analysis.height - 4}">'
        f"page {analysis.page_number} · {analysis.word_count} words · "
        f"{len(analysis.columns)} column candidates · {len(analysis.rows)} row bands"
        "</text>"
    )
    parts.append("</svg>")

    path = stem.with_name(f"{stem.name}.svg")
    path.write_text("\n".join(parts), encoding="utf-8")

    return path


def analyse(
    pdf_path: Path,
    out_dir: Path,
    *,
    pages: list[int] | None,
    labels: bool,
) -> list[Path]:
    out_dir.mkdir(parents=True, exist_ok=True)
    written: list[Path] = []

    with pdfplumber.open(str(pdf_path)) as pdf:
        selected = pages or list(range(1, len(pdf.pages) + 1))

        for number in selected:
            if number < 1 or number > len(pdf.pages):
                print(f"page {number} is outside 1..{len(pdf.pages)}", file=sys.stderr)
                continue

            page = pdf.pages[number - 1]
            analysis = analyse_page(page)

            stem = out_dir / f"{pdf_path.stem}-p{number}"

            json_path = stem.with_name(f"{stem.name}.json")
            json_path.write_text(json.dumps(analysis.to_json(), indent=2), encoding="utf-8")
            written.append(json_path)

            written.extend(write_csvs(analysis, page, stem))
            written.append(write_svg(analysis, stem, labels=labels))

            print(
                f"page {number}: {analysis.word_count} words, {analysis.char_count} chars, "
                f"{len(analysis.columns)} column candidates, {len(analysis.rows)} row bands"
                + (f", rotation {analysis.rotation}deg" if analysis.rotation else "")
                + (" — NO EXTRACTABLE TEXT" if analysis.is_image_only else "")
                + (
                    " — SCANNED PAGE: text is OCR output, coordinates are estimates"
                    if analysis.looks_scanned
                    else ""
                )
            )

    return written


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="layout_analysis",
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("pdf", type=Path)
    parser.add_argument("--page", type=int, action="append", dest="pages",
                        help="page to analyse; repeat for several. Default: all pages")
    parser.add_argument("--out", type=Path, default=Path("layout-analysis"))
    parser.add_argument("--no-labels", action="store_true",
                        help="omit word text and reading-order numbers from the SVG")
    args = parser.parse_args(argv)

    if not args.pdf.exists():
        print(f"no such file: {args.pdf}", file=sys.stderr)
        return 2

    written = analyse(args.pdf, args.out, pages=args.pages, labels=not args.no_labels)

    print(f"\nwrote {len(written)} files to {args.out}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
