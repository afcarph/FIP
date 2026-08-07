# Runbook — recovery

For when data in the database is wrong, rather than missing.

## The thing that makes recovery possible

**Every original PDF is kept.** `DOE_PDF_ARCHIVE_PATH`, sharded by checksum.
The DOE does not keep superseded weeks accessible, so a document not in that
directory is gone for good. Before anything else: confirm the archive is on
persistent disk and is being backed up.

```bash
curl -s https://fip.nelleeph.com/api/v1/health | jq '.data.checks.storage'
```

Because the originals exist, a bad extraction is repairable — fix the
extractor, re-run it over the archive, done. Without them, a bad extraction is
permanent.

## Re-extracting after an extractor fix

A fix to the reader does not touch data already stored. The duplicate guard
means a normal run will skip every document it already holds, so the defect
stays in the database while the tests say it is gone.

```bash
cd doe-pdf-ingest

# One document.
python pipeline.py --replay storage/pdfs/a4/VFO-PRICE-MONITORING-080426….pdf

# Everything in the archive. Failures are logged and the walk continues, so one
# unreadable document does not leave the rest unrepaired.
python pipeline.py --replay storage/pdfs/
```

`--replay` deliberately bypasses the duplicate guard — that is its whole
purpose. Storing a report replaces that region-week's rows rather than adding
to them, so replaying is idempotent and safe to repeat.

Verify against the API afterwards, not against the log:

```bash
curl -s 'https://fip.nelleeph.com/api/v1/fuel/reports?per_page=100' \
  | jq -r '.data[] | "\(.region)  \(.coverage_label)  monitoring=\(.monitoring_date)  q=\(.quality)"'
```

This is how the null `monitoring_date` on every REGIONS 6-8 report was
repaired: the reader was fixed, and then the stored reports were replayed
through it.

## A correction published by the DOE

The DOE sometimes re-issues a week's PDF. That document has different bytes but
the same region and week.

Nothing needs doing. The checksum says it is a new document; the unique
constraint on `(region, coverage_start)` says it is the same week; storage
treats it as a **correction** and replaces that week's rows. Withdrawn figures
do not survive.

Confirm it happened:

```sql
SELECT region, coverage_start, checksum, updated_at, rows_count
FROM fuel_reports WHERE region = 'NCR' ORDER BY coverage_start DESC LIMIT 5;
```

## A report imported with bad data

Judge it by `quality` and row count before deleting anything.

```sql
SELECT id, region, coverage_label, extractor, quality, areas_count, rows_count
FROM fuel_reports ORDER BY quality ASC LIMIT 10;
```

Anything stored scored at least 0.55; below ~0.9 is worth opening the source
PDF via `source_url`.

If a report genuinely must go:

```sql
DELETE FROM fuel_reports WHERE id = ?;   -- fuel_prices cascade
```

Then re-import it from the archive with `--replay`. Do not delete without first
confirming the PDF is still in the archive — otherwise the week is unrecoverable.

**Before running a DELETE on production, take a database snapshot.**

## Prices that look wrong but are not

Diesel above ₱110 in remote island municipalities is the DOE's own published
figure, not an extraction defect. This was investigated once and the bounds
were deliberately *not* tightened — doing so would discard real data. Check the
source PDF via `source_url` before concluding the extractor is at fault.

## The archive is lost

Then re-extraction is impossible for any week the DOE has since superseded, and
the database is the only copy of those figures. Do not run destructive repairs.
Re-import what the CMS still serves:

```bash
python pipeline.py --backfill
```

and accept that older weeks are gone.

## Rolling back a deployment

The RC1 migration is additive and every new column is nullable, so an older API
runs against the new schema unchanged. Roll code back first. Reverse the
migration only if the schema itself is the fault:

```bash
php artisan migrate:rollback --step=1
```

Note that an ingest writing phase timings against a rolled-back schema does not
fail — the run log drops counters whose column does not exist. The timings stop
being recorded; nothing breaks.
