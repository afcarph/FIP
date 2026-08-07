"""Restore the link from a stored report back to the published document.

A replay reads a PDF off disk and has no URL to offer. `store_report` used to
write that absence over the URL discovery had recorded, so replaying the
archive stripped `source_url` from every report at once. The write path is
fixed; this repairs what it already erased.

Matching is by the filename discovery derives from the CMS title — the same
derivation the downloader used when the file was stored, so the two agree by
construction rather than by a rule invented here. Reports whose document has
aged out of the library keep their NULL: an invented URL is worse than an
absent one.

    python repair_source_urls.py --dry-run
    python repair_source_urls.py --pages 40
"""

from __future__ import annotations

import argparse

from sqlalchemy import select

from discovery import GraphQlDiscoveryProvider
from logger import get_logger
from models import FuelReport
from settings import get_settings
from storage import session_scope

log = get_logger(__name__)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pages", type=int, default=40, help="library pages to walk")
    parser.add_argument("--dry-run", action="store_true", help="report, change nothing")
    args = parser.parse_args(argv)

    settings = get_settings().model_copy(update={"graphql_max_pages": args.pages})
    provider = GraphQlDiscoveryProvider(settings)

    # A high limit, because this walks for coverage rather than for the few
    # documents a daily run needs.
    candidates = provider.discover(limit=100_000)

    by_filename = {candidate.filename: candidate.url for candidate in candidates}

    log.info("Discovered %d candidate(s) across %d page(s)", len(candidates), args.pages)

    repaired = 0
    unmatched: list[str] = []

    with session_scope() as session:
        reports = session.scalars(select(FuelReport).where(FuelReport.source_url.is_(None))).all()

        log.info("%d report(s) have no source_url", len(reports))

        for report in reports:
            url = by_filename.get(report.pdf_filename)

            if url is None:
                unmatched.append(report.pdf_filename)
                continue

            if not args.dry_run:
                report.source_url = url

            repaired += 1

        if args.dry_run:
            session.rollback()

    log.info(
        "%s %d report(s); %d still unmatched",
        "Would repair" if args.dry_run else "Repaired",
        repaired,
        len(unmatched),
    )

    for name in unmatched[:15]:
        log.info("  unmatched: %s", name)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
