"""Orchestration: browser, interception, parse, import, run log.

    Start Playwright
      ↓  open the DOE dashboard
      ↓  let its own JavaScript issue getSchema / batchedDataV2
      ↓  intercept and decode every response
      ↓  build the id → label → column mapping from getSchema
      ↓  walk dataResponse → dataSubset → tableDataset
      ↓  columns → records
      ↓  insert or correct in MySQL

Run it directly::

    python scraper.py                     # one run
    python scraper.py --dry-run           # parse and report, write nothing
    python scraper.py --replay captures/  # re-parse a capture, no browser
    python scraper.py --headed            # watch it
"""

from __future__ import annotations

import argparse
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from config import DATA_ENDPOINT_MARKER
from database import (
    ImportResult,
    finish_run_log,
    init_db,
    session_scope,
    start_run_log,
    upsert_prices,
)
from interceptor import ResponseInterceptor, decode_payload
from logger import get_logger, new_run_id, prune_captures
from mapper import FieldMapping, build_mapping
from models import ScraperLog
from parser import parse_payloads
from settings import Settings, get_settings

log = get_logger(__name__)


class ScrapeError(RuntimeError):
    """A run failed in a way worth retrying."""


@dataclass
class ScrapeOutcome:
    """What a single attempt produced."""

    records: list[dict[str, Any]]
    mapping: FieldMapping
    errors: list[str]

    @property
    def is_empty(self) -> bool:
        return not self.records


# ---------------------------------------------------------------------------
# Collection
# ---------------------------------------------------------------------------


def collect_payloads(settings: Settings) -> ResponseInterceptor:
    """Drive a browser to the dashboard and return what it said.

    The browser is here because the Looker request is signed by the page. We do
    not read anything the browser renders — only what it fetches.
    """
    # Imported here, not at module scope, so the parsing modules and their
    # tests import without Playwright or its browsers installed.
    from playwright.sync_api import TimeoutError as PlaywrightTimeout  # noqa: PLC0415
    from playwright.sync_api import sync_playwright  # noqa: PLC0415

    interceptor = ResponseInterceptor()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=settings.headless,
            args=[
                # Required in a container: Chromium's sandbox needs privileges
                # the image deliberately does not have.
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
            ],
        )

        try:
            context = browser.new_context(
                user_agent=settings.user_agent,
                viewport={
                    "width": settings.viewport_width,
                    "height": settings.viewport_height,
                },
                locale="en-PH",
                timezone_id=settings.timezone,
            )
            context.set_default_timeout(settings.browser_timeout_ms)

            page = context.new_page()

            # Attached before navigating. Looker issues its first getSchema
            # during the initial render, so a handler registered after goto
            # misses it and every field falls back to its internal id.
            interceptor.attach(page)

            log.info("Opening dashboard", extra={"url": settings.dashboard_url})

            try:
                page.goto(
                    settings.dashboard_url,
                    wait_until="domcontentloaded",
                    timeout=settings.browser_timeout_ms,
                )
            except PlaywrightTimeout as exc:
                raise ScrapeError(f"Dashboard did not load: {exc}") from exc

            _wait_for_data(page, interceptor, settings)

        finally:
            browser.close()

    log.info("Collection finished", extra={"interceptor": interceptor.summary()})

    return interceptor


def _wait_for_data(page: Any, interceptor: ResponseInterceptor, settings: Settings) -> None:
    """Give the dashboard time to fetch everything.

    Looker renders tiles lazily, so the last ``batchedDataV2`` routinely lands
    well after the page reports itself loaded. Waiting on network idle alone
    captures the first tile and misses the rest.

    Scrolling is used to trigger the lazy tiles. That is an interaction, not
    extraction — nothing here reads what is on screen.
    """
    from playwright.sync_api import TimeoutError as PlaywrightTimeout  # noqa: PLC0415

    try:
        page.wait_for_load_state("networkidle", timeout=settings.browser_timeout_ms)
    except PlaywrightTimeout:
        # Looker holds a long-poll open, so networkidle sometimes never
        # arrives. Not fatal: the data requests have usually completed.
        log.debug("Network never went idle; continuing on the settle timeout")

    deadline = time.monotonic() + (settings.settle_timeout_ms / 1000)
    last_count = len(interceptor.data_responses)
    quiet_since = time.monotonic()

    while time.monotonic() < deadline:
        page.mouse.wheel(0, 1200)
        page.wait_for_timeout(1000)

        current = len(interceptor.data_responses)

        if current != last_count:
            last_count = current
            quiet_since = time.monotonic()
            continue

        # Three quiet seconds after at least one dataset means the dashboard
        # has stopped fetching. Waiting out the full timeout every run would
        # add 20s to a job that is otherwise done.
        if current > 0 and (time.monotonic() - quiet_since) > 3:
            break

    if not interceptor.has_data:
        raise ScrapeError(
            f"No {DATA_ENDPOINT_MARKER} responses were intercepted. "
            "The dashboard URL may have changed, or the report is no longer public."
        )


# ---------------------------------------------------------------------------
# One attempt
# ---------------------------------------------------------------------------


def scrape_once(settings: Settings) -> ScrapeOutcome:
    """Collect, map and parse. No writes."""
    interceptor = collect_payloads(settings)

    mapping = build_mapping(interceptor.schema_payloads())

    if not mapping.labels:
        # getReport carries the same field definitions and is a usable fallback
        # when the schema call is served from the browser cache.
        log.warning("No getSchema captured; falling back to getReport for labels")
        mapping = build_mapping(interceptor.report_payloads())

    if not mapping.is_usable:
        raise ScrapeError(
            "Field mapping is unusable — no station or no price column was "
            f"recognised ({mapping.summary()}). The dashboard's labels have "
            "probably changed; check the captured getSchema payload."
        )

    records, errors = parse_payloads(interceptor.data_payloads(), mapping)
    errors.extend(interceptor.failures)

    return ScrapeOutcome(records=records, mapping=mapping, errors=errors)


def replay(directory: Path) -> ScrapeOutcome:
    """Re-parse captured payloads without touching the network."""
    schema_files = sorted(directory.glob("*getSchema*.json"))
    data_files = sorted(directory.glob("*batchedDataV2*.json"))

    if not data_files:
        raise ScrapeError(f"No batchedDataV2 captures in {directory}")

    log.info(
        "Replaying %d data and %d schema captures from %s",
        len(data_files),
        len(schema_files),
        directory,
    )

    schema_payloads = [decode_payload(path.read_bytes()) for path in schema_files]
    data_payloads = [decode_payload(path.read_bytes()) for path in data_files]

    mapping = build_mapping(schema_payloads)
    records, errors = parse_payloads(data_payloads, mapping)

    return ScrapeOutcome(records=records, mapping=mapping, errors=errors)


# ---------------------------------------------------------------------------
# A full run, with retries
# ---------------------------------------------------------------------------


def run(
    *,
    dry_run: bool = False,
    replay_dir: Path | None = None,
    settings: Settings | None = None,
) -> ImportResult:
    """Execute one scheduled run end to end.

    Retries cover the failures that are actually transient — a slow dashboard,
    a dropped connection, a tile that never rendered. A mapping that no longer
    matches the dashboard is not transient, but it is still retried: the cost is
    two extra minutes once a day, and the alternative is giving up on a run
    whose first attempt happened to catch a half-rendered page.
    """
    settings = settings or get_settings()
    run_id = new_run_id()

    log.info("Run starting", extra={"run_id": run_id, "dry_run": dry_run})

    init_db()
    log_id = start_run_log()

    attempts = 0
    outcome: ScrapeOutcome | None = None
    failures: list[str] = []

    while attempts < settings.max_attempts:
        attempts += 1

        try:
            outcome = replay(replay_dir) if replay_dir else scrape_once(settings)

            if outcome.is_empty:
                raise ScrapeError("The run parsed zero records")

            break

        except Exception as exc:
            message = f"attempt {attempts}/{settings.max_attempts}: {type(exc).__name__}: {exc}"
            failures.append(message)
            log.warning("Attempt failed: %s", message)

            if attempts >= settings.max_attempts:
                log.error("Giving up after %d attempts", attempts)
                finish_run_log(
                    log_id,
                    status=ScraperLog.STATUS_FAILED,
                    attempts=attempts,
                    errors=failures,
                )
                return ImportResult(errors=failures)

            # Linear rather than exponential: the dashboard is slow, not rate
            # limiting us, and a run that must finish before the 06:00 window
            # closes cannot afford a doubling backoff.
            delay = settings.retry_base_delay_s * attempts
            log.info("Retrying in %.0fs", delay)
            time.sleep(delay)

    assert outcome is not None  # the loop either breaks with one or returns

    if dry_run:
        log.info("Dry run: %d records parsed, nothing written", len(outcome.records))
        _log_sample(outcome)
        finish_run_log(
            log_id,
            status=ScraperLog.STATUS_SUCCESS,
            result=ImportResult(processed=len(outcome.records), skipped=len(outcome.records)),
            attempts=attempts,
            errors=outcome.errors,
        )
        return ImportResult(processed=len(outcome.records), skipped=len(outcome.records))

    with session_scope() as session:
        result = upsert_prices(session, outcome.records)

    result.errors.extend(outcome.errors)

    # Partial, not failed: rows were written, and a status that says otherwise
    # would have an operator chasing an outage that did not happen.
    status = (
        ScraperLog.STATUS_PARTIAL
        if result.errors and result.wrote_anything
        else ScraperLog.STATUS_SUCCESS
        if not result.errors
        else ScraperLog.STATUS_PARTIAL
    )

    finish_run_log(
        log_id,
        status=status,
        result=result,
        attempts=attempts,
        errors=failures,
    )

    pruned = prune_captures()
    if pruned:
        log.info("Pruned %d expired captures", pruned)

    log.info("Run finished: %s", result.summary())

    return result


def _log_sample(outcome: ScrapeOutcome) -> None:
    """Show a couple of parsed records, so a dry run is worth reading."""
    for record in outcome.records[:3]:
        log.info(
            "Sample: %s / %s / %s — %s",
            record.get("company"),
            record.get("station"),
            record.get("city"),
            {
                key: value
                for key, value in record.items()
                if key in ("price_date", "ron91", "ron95", "diesel")
            },
        )


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="scraper",
        description="Collect DOE fuel prices from the Looker Studio dashboard.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="parse and report without writing to the database",
    )
    parser.add_argument(
        "--replay",
        type=Path,
        metavar="DIR",
        help="re-parse captured payloads instead of opening a browser",
    )
    parser.add_argument(
        "--headed",
        action="store_true",
        help="show the browser (overrides DOE_HEADLESS)",
    )
    parser.add_argument(
        "--url",
        help="dashboard URL (overrides DOE_DASHBOARD_URL)",
    )

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)

    settings = get_settings()
    if args.headed:
        settings = settings.model_copy(update={"headless": False})
    if args.url:
        settings = settings.model_copy(update={"dashboard_url": args.url})

    result = run(dry_run=args.dry_run, replay_dir=args.replay, settings=settings)

    if not result.processed and result.errors:
        # Non-zero so cron mails the operator and a container restart policy
        # can see the failure.
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
