# North Luzon: not a layout problem

**The North Luzon reports are scanned paper with an OCR text layer. They are a
different kind of document from the ones the extractor reads, and no amount of
parser work on their coordinates will make them reliable.**

This was established with `layout_analysis.py`, before any parser change — the
tool exists so that the next layout is understood rather than guessed at.

## The evidence

Measured across every page of each document:

| Document | Pages | Full-page images | Vector objects | Dominant font |
|---|---:|---:|---:|---|
| NCR 28 Jul 2026 | 2 | 0 | 242 | ArialNarrow-Italic (embedded subset) |
| Visayas 25 Nov 2025 | 8 | 0 | 1,314 | ArialNarrow (embedded subset) |
| **North Luzon 21–27 Jul 2026** | 15 | **15** | **0** | Helvetica-Oblique |
| **North Luzon 14–20 Jul 2026** | 15 | **15** | **0** | Helvetica |

The separation is absolute. Every North Luzon page is one image covering the
whole page with no vector drawing beneath it; every page of the two working
layouts is vector with no full-page image at all.

Four more signatures agree:

- **Base-14 fonts only.** `Helvetica`, `Times-Italic` — the fonts an OCR engine
  labels text with. The working layouts carry embedded subsets (`BCDEEE+…`),
  which is what a real typesetter produces.
- **`(cid:9)` glyphs** — characters with no usable unicode mapping.
- **Duplicated tokens at sub-point offsets.** On page 3, `95` appears at
  x = 158.9, 158.9, 159.1, 159.1 — four copies inside half a point.
- **169 non-upright characters that spell nothing.** `U-`, `0z 0)C0`,
  `a eR,010`. Not rotated headings; recogniser noise.

Pages are also rotated 270° (835×592 landscape), which is real but incidental.

## What this means for the parser

The extractor anchors columns on a header row of brand names. On these
documents that header does not exist as text — OCR did not recover it. The
column candidates the tool finds (6 on page 3) are gaps in *recognised* ink,
and their x-positions are a recogniser's estimate of where glyphs sat on a
scan, not typeset positions.

So a parser built against them would be a parser fitted to one OCR run of one
scan. Prices land correctly in the products axis — `RON 95`, `RON 91`,
`DIESEL`, `DIESEL PLUS`, `KEROSENE` all read cleanly — but there is nothing
trustworthy to attribute them to a brand with. Publishing those numbers under
brand names inferred from OCR geometry would be inventing attribution.

**Recommendation for RC1: keep rejecting these documents.** The pipeline
already does, cleanly and as per-document rejections rather than run failures,
so they cost nothing but a line in the run log. Reaching them properly needs
one of:

1. a digital original from the DOE, which would make them a normal layout;
2. a deliberate OCR pipeline — deskew, re-recognise, reconcile against the
   image — which is a project, not a parser tweak;
3. accepting product-level prices without brand attribution, which changes what
   the data means and is a product decision, not a technical one.

None belong in RC1.

## Reproducing this

```bash
cd doe-pdf-ingest
python layout_analysis.py <report.pdf> --page 3 --out ./analysis
```

Writes, per page:

| File | Contents |
|---|---|
| `…-p3.json` | page geometry, rotation, scan detection, column candidates with the gap that produced each, row bands, and every word with bbox, reading order, row and column |
| `…-p3-words.csv` | the same words, for a spreadsheet or a diff between two documents |
| `…-p3-chars.csv` | every character with bbox, size, font and uprightness |
| `…-p3.svg` | the page to scale — word boxes, row bands, candidate column boundaries, reading-order numbers |

A scanned page announces itself:

```
page 3: 350 words, 1641 chars, 6 column candidates, 40 row bands, rotation 270deg
        — SCANNED PAGE: text is OCR output, coordinates are estimates
```

Overlays for both documents are kept in
[`north-luzon/`](north-luzon) — the North Luzon page next to an NCR page shows
the difference at a glance.

## A note on the tool's own defaults

Two things it deliberately does not do, because this project has shipped both
bugs:

- Row bands are found by clustering word midpoints against a fixed per-band
  anchor, not by `int(top // n)` bucketing — which splits a row in two whenever
  a baseline straddles a boundary — and not by comparing against the band's
  growing bottom edge, which chains every row on a page into one. The first
  draft of this tool did the latter and reported 2 row bands for a 350-word
  table.
- Column candidates are labelled candidates throughout. They describe where the
  page has no ink. Whether a gap separates two columns or two words in a sparse
  row is what the overlay is for.
