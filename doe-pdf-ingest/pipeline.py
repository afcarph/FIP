"""Orchestration.

    discover → download → store the PDF → extract → validate → save → notify

Run it directly::

    python pipeline.py                    # a normal daily run
    python pipeline.py --dry-run          # discover and extract, write nothing
    python pipeline.py --backfill         # walk deeper into the archive
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
import json
import sys
import time
from collections import Counter
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path

import requests

from discovery import DiscoveredPdf, DiscoveryProvider, ManualSeedProvider, build_provider
from downloader import DownloadError, PdfDownloader
from extractor import ExtractionError, extract
from logger import get_logger, new_run_id
from models import ImportRun
from settings import Settings, get_settings
from storage import finish_run, is_duplicate, session_scope, start_run, store_report
from validator import validate

log = get_logger(__name__)

#: The phases a run is measured in. Named once so the model columns, the run
#: log and the health dashboard cannot drift apart.
PHASES = ("discovery", "download", "extraction", "validation", "import")


class PipelineResult:
    """Counters for the run log and the exit code."""

    def __init__(self) -> None:
        self.discovered = 0
        self.downloaded = 0
        self.imported = 0
        self.skipped = 0
        self.records = 0
        self.replaced = 0

        # Run-level failures: discovery unreachable, storage broken, the run
        # itself unable to do its job. These decide the status.
        self.errors: list[str] = []

        # Per-document rejections: a PDF that is not a price table, a layout
        # the extractor cannot read. Expected, recurring, and *not* a run
        # failure — the DOE publishes LPG sheets and regional layouts we do not
        # handle, and those documents will be rejected every single day. Mixing
        # them into `errors` is what made a healthy idempotent run report
        # `failed`, which would page someone every morning forever.
        self.rejections: list[str] = []

        # Candidates that parsed into a report. Distinct from `discovered`:
        # discovery matches on title, and a matching title is not a price
        # table.
        self.reports_parsed = 0

        # Per-phase wall clock, in milliseconds, accumulated across documents.
        # One total says a run got slower; it does not say whether the DOE's
        # API, the CPU or the database was responsible, and those page
        # different people.
        self.phase_ms: dict[str, int] = dict.fromkeys(PHASES, 0)

        #: Which extractor read each report, counted. A layout changing
        #: silently shows up here first, as a report arriving from a different
        #: extractor than last week.
        self.parser_versions: Counter[str] = Counter()

        #: Whatever the discovery provider chose to report about its own work.
        self.discovery_stats: dict[str, int] = {}

        #: Whole-run wall clock. Not the sum of the phases: a run also spends
        #: time outside them, and a total that silently equalled the sum would
        #: hide exactly that.
        self.total_ms: int | None = None

    @contextmanager
    def timing(self, phase: str) -> Iterator[None]:
        """Add this block's wall clock to a phase.

        A context manager rather than timestamps at call sites because the
        phases are entered from several places — a mis-paired start and stop
        would silently under-report, and an under-reported phase looks healthy.
        """
        started = time.monotonic()

        try:
            yield
        finally:
            self.phase_ms[phase] += int((time.monotonic() - started) * 1000)

    def summary(self) -> str:
        return (
            f"discovered={self.discovered} downloaded={self.downloaded} "
            f"imported={self.imported} skipped={self.skipped} "
            f"records={self.records} rejected={len(self.rejections)} "
            f"errors={len(self.errors)} "
            + " ".join(f"{phase}={self.phase_ms[phase]}ms" for phase in PHASES)
        )

    def run_log_fields(self) -> dict[str, int | str | None]:
        """Everything this run wants recorded, keyed by column name.

        `finish_run` sets only fields the model actually has, so a key added
        here before its migration lands is ignored rather than fatal — which
        matters because the ingest and the schema deploy separately.
        """
        return {
            "pdfs_discovered": self.discovered,
            "pdfs_downloaded": self.downloaded,
            "reports_discovered": self.reports_parsed,
            "reports_imported": self.imported,
            "reports_skipped": self.skipped,
            "reports_rejected": len(self.rejections),
            "records_imported": self.records,
            "records_updated": self.replaced,
            "discovery_duration_ms": self.phase_ms["discovery"],
            "download_duration_ms": self.phase_ms["download"],
            "extraction_duration_ms": self.phase_ms["extraction"],
            "validation_duration_ms": self.phase_ms["validation"],
            "import_duration_ms": self.phase_ms["import"],
            "total_duration_ms": self.total_ms,
            "parser_versions": (
                json.dumps(dict(sorted(self.parser_versions.items())))
                if self.parser_versions
                else None
            ),
            "graphql_pages": self.discovery_stats.get("graphql_pages"),
        }

    def status(self) -> str:
        """What this run should be read as.

        The question each status answers is "does a human need to look at
        this?", not "did anything go wrong". Documents the DOE publishes that
        we cannot use go wrong on every run, by design, and a status that
        reflects them is a status nobody reads.
        """
        # The run could not do its job: nothing imported, nothing recognised as
        # already held, and something broke.
        if self.errors and self.imported == 0 and self.skipped == 0:
            return ImportRun.STATUS_FAILED

        # Something broke but the run still did work.
        if self.errors:
            return ImportRun.STATUS_PARTIAL

        if self.imported == 0:
            # Everything found was already held. The normal outcome between
            # weekly publications, and the case that used to read as failure.
            if self.skipped > 0:
                return ImportRun.STATUS_NO_CHANGES

            # Candidates were found and none could be used at all. Not a quiet
            # week — a layout change, or a discovery query returning the wrong
            # documents.
            return ImportRun.STATUS_FAILED if self.discovered else ImportRun.STATUS_NO_CHANGES

        # Imported, but some documents were unusable. Worth seeing, not worth
        # paging for.
        return ImportRun.STATUS_PARTIAL if self.rejections else ImportRun.STATUS_SUCCESS


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
        with result.timing("download"):
            pdf = downloader.fetch(candidate.url, candidate.filename)
    except (DownloadError, requests.RequestException) as exc:
        result.rejections.append(f"{candidate.filename}: download failed: {exc}")
        return

    if pdf.newly_downloaded:
        result.downloaded += 1

    # Checked before extraction, which is the expensive step: most of what
    # discovery finds on a daily run was imported days ago.
    if not dry_run:
        with result.timing("import"), session_scope() as session:
            if is_duplicate(session, pdf.checksum):
                result.skipped += 1
                log.info("Already imported: %s", candidate.filename)
                return

    try:
        with result.timing("extraction"):
            report = extract(pdf.path, settings)
    except ExtractionError as exc:
        # Not fatal for the run. A file discovery picked up that is not a price
        # table — an Oil Monitor summary, a circular — lands here, and so does
        # a genuine layout change. Both are logged; neither costs the regions
        # that read cleanly.
        result.rejections.append(f"{candidate.filename}: {exc}")
        return

    result.reports_parsed += 1

    if report.extractor:
        result.parser_versions[report.extractor] += 1

    with result.timing("validation"):
        validation = validate(report, settings)

    result.rejections.extend(f"{candidate.filename}: {error}" for error in validation.errors)

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
        with result.timing("import"), session_scope() as session:
            stored = store_report(
                session,
                report,
                checksum=pdf.checksum,
                filename=pdf.filename,
                source_url=candidate.url,
                pdf_path=str(pdf.path),
            )
    except Exception as exc:
        result.rejections.append(f"{candidate.filename}: could not be stored: {exc}")
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
    started = time.monotonic()

    log.info("Run starting", extra={"run_id": run_id, "dry_run": dry_run, "backfill": backfill})

    log_id = None if dry_run else start_run()
    downloader = PdfDownloader(settings)

    try:
        # The pipeline knows only the interface. A single URL is a provider
        # yielding one candidate rather than a branch through the run, which is
        # what keeps `--url` and a scheduled run on the same path.
        if backfill:
            # Raises the page ceiling rather than removing it: the archive is
            # ~150 pages, and an unbounded walk is how a backfill turns into a
            # denial of service against a public agency's API.
            settings = settings.model_copy(
                update={"graphql_max_pages": settings.backfill_max_pages}
            )

        provider: DiscoveryProvider = (
            ManualSeedProvider(urls=[url]) if url else build_provider(settings)
        )

        limit = None if (url or backfill) else settings.max_candidates_per_run

        with result.timing("discovery"):
            candidates = _with_retries(provider, limit, settings, result)

        result.discovered = len(candidates)
        result.discovery_stats = dict(getattr(provider, "last_stats", {}) or {})

        for candidate in candidates:
            process_pdf(candidate, downloader, settings, result, dry_run=dry_run)

    except Exception as exc:
        result.errors.append(f"Run failed: {exc}")
        log.exception("Run failed")

    result.total_ms = int((time.monotonic() - started) * 1000)

    log.info("Run finished: %s", result.summary())

    if log_id is not None:
        finish_run(
            log_id,
            status=result.status(),
            counts=result.run_log_fields(),
            errors=[*result.errors, *result.rejections],
        )

    if not dry_run:
        _notify(result, settings)

        pruned = downloader.prune()
        if pruned:
            log.info("Pruned %d expired PDFs", pruned)

    return result


def _with_retries(
    provider: DiscoveryProvider,
    limit: int | None,
    settings: Settings,
    result: PipelineResult,
) -> list[DiscoveredPdf]:
    """Discovery, retried.

    The CMS is not highly available, and a transient 5xx would otherwise look
    like a week with nothing published — a silent failure, since an empty run
    is a legitimate outcome.
    """
    for attempt in range(1, settings.max_attempts + 1):
        found = provider.discover(limit)

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


def replay_all(directory: Path) -> PipelineResult:
    """Re-extract every PDF under a directory.

    An extractor fix only reaches data already stored if the documents it got
    wrong are run through it again. Fixing the reader and leaving the rows
    alone means the defect stays in the database and the tests say it is gone.

    Failures do not stop the walk: one unreadable document in an archive of
    fifty should not leave the other forty-nine unrepaired.
    """
    combined = PipelineResult()
    paths = sorted(directory.rglob("*.pdf"))

    log.info("Replaying %d stored PDFs under %s", len(paths), directory)

    for path in paths:
        try:
            outcome = replay(path)
        except Exception as exc:  # one bad document must not stop the rest
            log.warning("Replay failed for %s: %s", path.name, exc)
            combined.errors.append(f"{path.name}: {exc}")
            continue

        combined.discovered += outcome.discovered
        combined.imported += outcome.imported
        combined.skipped += outcome.skipped
        combined.records += outcome.records
        combined.errors.extend(outcome.errors)
        combined.rejections.extend(outcome.rejections)

    log.info("Replay of %s finished: %s", directory, combined.summary())

    return combined


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="pipeline",
        description="Ingest DOE fuel price monitoring PDFs.",
    )
    parser.add_argument("--dry-run", action="store_true", help="extract and report, write nothing")
    parser.add_argument("--backfill", action="store_true", help="walk the whole listing archive")
    parser.add_argument("--url", help="ingest one PDF by URL")
    parser.add_argument(
        "--replay",
        type=Path,
        metavar="PATH",
        help="re-extract a stored PDF, or every PDF under a directory",
    )

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)

    if args.replay:
        result = replay_all(args.replay) if args.replay.is_dir() else replay(args.replay)
    else:
        result = run(dry_run=args.dry_run, backfill=args.backfill, url=args.url)

    # Non-zero only when nothing landed and something went wrong. A week with
    # nothing new published is a success, and paging someone for it is how a
    # real failure gets ignored later.
    if result.status() == ImportRun.STATUS_FAILED:
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
