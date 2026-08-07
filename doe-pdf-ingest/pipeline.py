"""Orchestration.

    discover → download → store the PDF → extract → validate → save → notify

Run it directly::

    python pipeline.py                    # a normal daily run
    python pipeline.py --dry-run          # discover and extract, write nothing
    python pipeline.py --backfill         # walk the whole archive
    python pipeline.py --url <pdf-url>    # one document
    python pipeline.py --replay <path>    # re-extract a stored PDF

`--replay` is why the original PDFs are kept. Extraction is the part most
likely to need fixing — the DOE's layouts differ by region and change without
notice — and a fix is only worth having if it can be re-run against the
documents it would have got wrong. The DOE does not keep superseded weeks
accessible, so a PDF not stored here is gone.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

import requests

from discovery import DiscoveredPdf, PdfDiscovery
from downloader import DownloadError, PdfDownloader
from extractor import ExtractionError, extract
from logger import get_logger, new_run_id
from models import ImportRun
from settings import Settings, get_settings
from storage import finish_run, is_duplicate, session_scope, start_run, store_report
from validator import validate

log = get_logger(__name__)


class PipelineResult:
    """Counters for the run log and the exit code."""

    def __init__(self) -> None:
        self.discovered = 0
        self.downloaded = 0
        self.imported = 0
        self.skipped = 0
        self.records = 0
        self.replaced = 0
        self.errors: list[str] = []

    def summary(self) -> str:
        return (
            f"discovered={self.discovered} downloaded={self.downloaded} "
            f"imported={self.imported} skipped={self.skipped} "
            f"records={self.records} errors={len(self.errors)}"
        )

    def status(self) -> str:
        if self.imported == 0 and self.errors:
            return ImportRun.STATUS_FAILED
        if self.errors:
            return ImportRun.STATUS_PARTIAL
        if self.imported == 0:
            # Nothing new published. A success, and recorded as its own status
            # so a quiet week is distinguishable from a scheduler that died.
            return ImportRun.STATUS_NO_CHANGES

        return ImportRun.STATUS_SUCCESS


def process_pdf(
    candidate: DiscoveredPdf,
    downloader: PdfDownloader,
    settings: Settings,
    result: PipelineResult,
    *,
    dry_run: bool = False,
) -> None:
    """Download, extract, validate and store one document."""
    try:
        pdf = downloader.fetch(candidate.url, candidate.filename)
    except (DownloadError, requests.RequestException) as exc:
        result.errors.append(f"{candidate.filename}: download failed: {exc}")
        return

    if pdf.newly_downloaded:
        result.downloaded += 1

    # Checked before extraction, which is the expensive step: most of what
    # discovery finds on a daily run was imported days ago.
    if not dry_run:
        with session_scope() as session:
            if is_duplicate(session, pdf.checksum):
                result.skipped += 1
                log.info("Already imported: %s", candidate.filename)
                return

    try:
        report = extract(pdf.path, settings)
    except ExtractionError as exc:
        # Not fatal for the run. A file discovery picked up that is not a price
        # table — an Oil Monitor summary, a circular — lands here, and so does
        # a genuine layout change. Both are logged; neither costs the regions
        # that read cleanly.
        result.errors.append(f"{candidate.filename}: {exc}")
        return

    validation = validate(report, settings)
    result.errors.extend(f"{candidate.filename}: {error}" for error in validation.errors)

    if not validation.ok:
        return

    for warning in validation.warnings[:10]:
        log.warning("%s: %s", candidate.filename, warning)

    if dry_run:
        log.info("Dry run: %s would import %s", candidate.filename, report.summary())
        result.imported += 1
        result.records += validation.valid_rows
        return

    # Only what validation passed. See ValidationResult.accepted.
    report.prices = validation.accepted

    try:
        with session_scope() as session:
            stored = store_report(
                session,
                report,
                checksum=pdf.checksum,
                filename=pdf.filename,
                source_url=candidate.url,
                pdf_path=str(pdf.path),
            )
    except Exception as exc:
        result.errors.append(f"{candidate.filename}: could not be stored: {exc}")
        log.exception("Storing %s failed", candidate.filename)
        return

    if stored.skipped:
        result.skipped += 1
        return

    result.imported += 1
    result.records += stored.rows_written
    result.replaced += stored.rows_replaced


def run(
    *,
    dry_run: bool = False,
    backfill: bool = False,
    url: str | None = None,
    settings: Settings | None = None,
) -> PipelineResult:
    """One end-to-end execution."""
    settings = settings or get_settings()
    run_id = new_run_id()
    result = PipelineResult()

    log.info("Run starting", extra={"run_id": run_id, "dry_run": dry_run, "backfill": backfill})

    log_id = None if dry_run else start_run()
    downloader = PdfDownloader(settings)

    try:
        if url:
            candidates = [
                DiscoveredPdf(
                    url=url,
                    label="manual",
                    article_url="",
                    article_title="manual",
                )
            ]
        else:
            discovery = PdfDiscovery(settings)
            pages = settings.backfill_pages if backfill else settings.listing_pages
            candidates = _with_retries(discovery, pages, settings, result)

        if not backfill and not url:
            candidates = candidates[: settings.max_candidates_per_run]

        result.discovered = len(candidates)

        for candidate in candidates:
            process_pdf(candidate, downloader, settings, result, dry_run=dry_run)

    except Exception as exc:
        result.errors.append(f"Run failed: {exc}")
        log.exception("Run failed")

    log.info("Run finished: %s", result.summary())

    if log_id is not None:
        finish_run(
            log_id,
            status=result.status(),
            counts={
                "pdfs_discovered": result.discovered,
                "pdfs_downloaded": result.downloaded,
                "reports_imported": result.imported,
                "reports_skipped": result.skipped,
                "records_imported": result.records,
                "records_updated": result.replaced,
            },
            errors=result.errors,
        )

    if not dry_run:
        _notify(result, settings)

        pruned = downloader.prune()
        if pruned:
            log.info("Pruned %d expired PDFs", pruned)

    return result


def _with_retries(
    discovery: PdfDiscovery,
    pages: int,
    settings: Settings,
    result: PipelineResult,
) -> list[DiscoveredPdf]:
    """Discovery, retried.

    The portal is not highly available and a transient 5xx on the listing would
    otherwise look like a week with nothing published — which is a silent
    failure, since an empty run is a legitimate outcome.
    """
    for attempt in range(1, settings.max_attempts + 1):
        found = discovery.discover(pages)

        if found:
            return found

        if attempt < settings.max_attempts:
            delay = settings.retry_base_delay_s * attempt
            log.warning("Discovery found nothing; retrying in %.0fs", delay)
            time.sleep(delay)

    result.errors.append("Discovery found no documents after every attempt.")

    return []


def _notify(result: PipelineResult, settings: Settings) -> None:
    """Post the outcome to a webhook, if one is configured.

    Only when something happened. A daily "nothing new" message trains people
    to ignore the channel, which is how the message that matters gets missed.
    """
    if not settings.notify_webhook_url:
        return

    if result.imported == 0 and not result.errors:
        return

    payload = {
        "text": (
            f"DOE ingest: {result.summary()}"
            + (f"\nFirst error: {result.errors[0]}" if result.errors else "")
        ),
        "status": result.status(),
        "imported": result.imported,
        "records": result.records,
    }

    try:
        requests.post(settings.notify_webhook_url, json=payload, timeout=10)
    except requests.RequestException as exc:
        # A failed notification must not fail an import that worked.
        log.warning("Could not send notification: %s", exc)


def replay(path: Path, settings: Settings | None = None) -> PipelineResult:
    """Re-extract and re-store a PDF already on disk."""
    import hashlib  # noqa: PLC0415

    settings = settings or get_settings()
    new_run_id()
    result = PipelineResult()
    result.discovered = 1

    body = path.read_bytes()
    checksum = hashlib.sha256(body).hexdigest()

    try:
        report = extract(path, settings)
    except ExtractionError as exc:
        result.errors.append(str(exc))
        return result

    validation = validate(report, settings)
    result.errors.extend(validation.errors)

    if not validation.ok:
        return result

    with session_scope() as session:
        # Not the duplicate guard: a replay exists precisely to re-import a
        # document already stored, after the extractor has been fixed.
        stored = store_report(
            session,
            report,
            checksum=checksum,
            filename=path.name,
            source_url=None,
            pdf_path=str(path),
        )

    if stored.skipped:
        result.skipped = 1
    else:
        result.imported = 1
        result.records = stored.rows_written

    log.info("Replay finished: %s", result.summary())

    return result


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="pipeline",
        description="Ingest DOE fuel price monitoring PDFs.",
    )
    parser.add_argument("--dry-run", action="store_true", help="extract and report, write nothing")
    parser.add_argument("--backfill", action="store_true", help="walk the whole listing archive")
    parser.add_argument("--url", help="ingest one PDF by URL")
    parser.add_argument("--replay", type=Path, metavar="PATH", help="re-extract a stored PDF")

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)

    if args.replay:
        result = replay(args.replay)
    else:
        result = run(dry_run=args.dry_run, backfill=args.backfill, url=args.url)

    # Non-zero only when nothing landed and something went wrong. A week with
    # nothing new published is a success, and paging someone for it is how a
    # real failure gets ignored later.
    if result.imported == 0 and result.errors:
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
