#!/usr/bin/env python3
"""Ask the DOE CMS what it actually holds.

Read-only, unauthenticated, and deliberately independent of the ingest package:
when discovery and reality disagree, a diagnostic that shares the ingest's code
can only tell you what the ingest already believes. This talks to the source.

    python3 scripts/probe_doe_library.py --contains NCR --pages 15
    python3 scripts/probe_doe_library.py --contains "0804" --json
"""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.request

ENDPOINT = "https://prod-cms.doe.gov.ph/o/graphql"

# `flatten: true` is not optional. Without it the API returns only the root
# folder — 58 documents out of 14,898 — and every absence looks like proof.
QUERY = """
query Documents($siteKey: String!, $pageSize: Int!, $page: Int!) {
  documents(
    siteKey: $siteKey
    flatten: true
    pageSize: $pageSize
    page: $page
    sort: "dateModified:desc"
  ) {
    totalCount
    items { id title contentUrl dateModified }
  }
}
"""


def fetch(page: int, page_size: int, site_key: str, timeout: int) -> dict:
    body = json.dumps(
        {"query": QUERY, "variables": {"siteKey": site_key, "pageSize": page_size, "page": page}}
    ).encode()

    request = urllib.request.Request(
        ENDPOINT,
        data=body,
        headers={"Content-Type": "application/json", "User-Agent": "FIP-probe/1.0"},
    )

    with urllib.request.urlopen(request, timeout=timeout) as response:
        payload = json.load(response)

    if "errors" in payload:
        raise SystemExit(f"GraphQL errors: {json.dumps(payload['errors'])[:500]}")

    return payload["data"]["documents"]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--contains", action="append", default=[],
                        help="case-insensitive substring a title must contain; repeat to require all of them")
    parser.add_argument("--pages", type=int, default=10, help="pages of 100 to scan, newest first")
    parser.add_argument("--page-size", type=int, default=100)
    parser.add_argument("--site-key", default="guest")
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("--json", action="store_true", help="emit matches as JSON")
    args = parser.parse_args()

    needles = [needle.upper() for needle in args.contains]
    matches: list[dict] = []
    scanned = 0
    total = None

    for page in range(1, args.pages + 1):
        try:
            documents = fetch(page, args.page_size, args.site_key, args.timeout)
        except urllib.error.URLError as error:
            print(f"request failed on page {page}: {error}", file=sys.stderr)
            return 2

        total = documents["totalCount"]
        items = documents["items"]

        if not items:
            break

        scanned += len(items)
        matches.extend(
            item for item in items if all(needle in item["title"].upper() for needle in needles)
        )

    if args.json:
        json.dump({"total_count": total, "scanned": scanned, "matches": matches}, sys.stdout, indent=2)
        print()
        return 0

    print(f"library holds {total} documents; scanned the {scanned} newest")
    print(f"matching {' + '.join(args.contains) or 'everything'}: {len(matches)}")

    for item in matches:
        print(f"  {item['dateModified']}  {item['title']}")

    # An empty result is only meaningful if the scan was deep enough to be
    # evidence, so say how deep it went rather than leaving it to be assumed.
    if not matches:
        print(f"\nNo match in the {scanned} newest documents. Raise --pages to look further back.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
