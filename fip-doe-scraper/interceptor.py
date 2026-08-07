"""Response interception.

The dashboard's own JavaScript asks the Looker backend for its data. We let it,
and read the answers. Nothing in this module touches the DOM: there is no
selector, no ``inner_text``, no table walk and no image.

Why interception rather than replaying the request ourselves: the POST body is
signed with a token minted by the page at load, and the report id, page id and
tile ids are all regenerated when the DOE republishes. A browser that renders
the dashboard normally produces correct requests by construction.
"""

from __future__ import annotations

import json
from collections.abc import Iterable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from config import (
    DATA_ENDPOINT_MARKER,
    INTERCEPT_MARKERS,
    JSON_HIJACK_PREFIX,
    REPORT_ENDPOINT_MARKER,
    SCHEMA_ENDPOINT_MARKER,
)
from logger import capture_path, get_logger

log = get_logger(__name__)


class PayloadDecodeError(ValueError):
    """A response looked like ours but would not decode."""


def strip_json_prefix(text: str) -> str:
    """Remove Looker's anti-hijacking guard.

    Every body is served as ``)]}'`` followed by a newline and then the JSON.
    The guard exists so a ``<script src>`` pointing at the endpoint throws
    instead of leaking data; for us it is four bytes that must come off before
    anything will parse.
    """
    stripped = text.lstrip("﻿ \t\r\n")

    stripped = stripped.removeprefix(JSON_HIJACK_PREFIX)

    return stripped.lstrip("\r\n")


def decode_payload(raw: str | bytes) -> Any:
    """Strip the guard and decode.

    Raises :class:`PayloadDecodeError` rather than ``JSONDecodeError`` so a
    malformed body is handled as one failure mode by the caller, whatever
    produced it.
    """
    if isinstance(raw, bytes):
        try:
            raw = raw.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise PayloadDecodeError(f"Body was not UTF-8: {exc}") from exc

    body = strip_json_prefix(raw)

    if not body:
        raise PayloadDecodeError("Body was empty after stripping the prefix")

    try:
        return json.loads(body)
    except json.JSONDecodeError as exc:
        # The head of the body is the only useful clue when Looker answers with
        # an HTML error page or a consent interstitial instead of JSON.
        raise PayloadDecodeError(
            f"Body did not decode as JSON at position {exc.pos}: {body[:200]!r}"
        ) from exc


@dataclass
class CapturedResponse:
    """One intercepted response, decoded."""

    url: str
    kind: str
    status: int
    payload: Any
    byte_length: int


@dataclass
class ResponseInterceptor:
    """Collects Looker responses from a page.

    Attach before navigating. Looker issues its first ``getSchema`` during the
    initial render, so a handler registered after ``goto`` misses the schema and
    every field falls back to its internal id.
    """

    data_responses: list[CapturedResponse] = field(default_factory=list)
    schema_responses: list[CapturedResponse] = field(default_factory=list)
    report_responses: list[CapturedResponse] = field(default_factory=list)
    failures: list[str] = field(default_factory=list)

    _seen_urls: set[str] = field(default_factory=set, repr=False)

    # -- wiring --------------------------------------------------------------

    def attach(self, page: Any) -> None:
        """Register the response handler on a Playwright page."""
        page.on("response", self._on_response)
        log.debug("Interceptor attached")

    def _classify(self, url: str) -> str | None:
        """Which endpoint a URL is, or ``None`` if we do not care about it."""
        for marker in INTERCEPT_MARKERS:
            if marker in url:
                return marker
        return None

    def _on_response(self, response: Any) -> None:
        """Playwright callback. Must never raise.

        An exception thrown here propagates into Playwright's event loop and
        tears down the page, losing every response that had not yet arrived —
        so a single malformed body would cost the entire run.
        """
        url = response.url

        kind = self._classify(url)
        if kind is None:
            return

        try:
            body = response.body()
        except Exception as exc:
            # Routine rather than alarming: bodies of responses the page has
            # already discarded are not retrievable.
            self.failures.append(f"Could not read body for {url}: {exc}")
            log.debug("Body unavailable", extra={"url": url, "error": str(exc)})
            return

        try:
            payload = decode_payload(body)
        except PayloadDecodeError as exc:
            self.failures.append(f"{kind} at {url} did not decode: {exc}")
            log.warning("Undecodable payload", extra={"url": url, "kind": kind})
            self._write_capture(f"{kind}-undecodable.txt", body)
            return

        captured = CapturedResponse(
            url=url,
            kind=kind,
            status=response.status,
            payload=payload,
            byte_length=len(body),
        )

        self._record(captured)

    def _record(self, captured: CapturedResponse) -> None:
        """File a decoded response under its endpoint."""
        if captured.kind == DATA_ENDPOINT_MARKER:
            bucket = self.data_responses
        elif captured.kind == SCHEMA_ENDPOINT_MARKER:
            bucket = self.schema_responses
        elif captured.kind == REPORT_ENDPOINT_MARKER:
            bucket = self.report_responses
        else:  # pragma: no cover - _classify only returns known markers
            return

        bucket.append(captured)

        log.info(
            "Captured %s (%d bytes)",
            captured.kind,
            captured.byte_length,
            extra={"url": captured.url, "status": captured.status},
        )

        index = len(bucket)
        self._write_capture(
            f"{captured.kind}-{index:02d}.json",
            json.dumps(captured.payload, ensure_ascii=False),
        )

    def _write_capture(self, name: str, body: str | bytes) -> None:
        """Persist a raw payload for replay, if capturing is enabled."""
        path = capture_path(name)
        if path is None:
            return

        try:
            data = body if isinstance(body, bytes) else body.encode("utf-8")
            path.write_bytes(data)
        except OSError as exc:
            # Losing a capture is not worth failing a run that is otherwise
            # working; a full disk here would take the prices down with it.
            log.debug("Could not write capture %s: %s", name, exc)

    # -- results -------------------------------------------------------------

    @property
    def has_data(self) -> bool:
        return bool(self.data_responses)

    @property
    def has_schema(self) -> bool:
        return bool(self.schema_responses)

    def data_payloads(self) -> list[Any]:
        """Decoded ``batchedDataV2`` payloads, in arrival order."""
        return [response.payload for response in self.data_responses]

    def schema_payloads(self) -> list[Any]:
        """Decoded ``getSchema`` payloads."""
        return [response.payload for response in self.schema_responses]

    def report_payloads(self) -> list[Any]:
        """Decoded ``getReport`` payloads."""
        return [response.payload for response in self.report_responses]

    def summary(self) -> str:
        return (
            f"data={len(self.data_responses)} schema={len(self.schema_responses)} "
            f"report={len(self.report_responses)} failures={len(self.failures)}"
        )


def load_captured_payloads(paths: Iterable[str | Path]) -> list[Any]:
    """Decode payloads from disk.

    Replaying yesterday's capture is how a parser change is tested against real
    data without starting a browser or putting load on the DOE.
    """
    return [decode_payload(Path(path).read_bytes()) for path in paths]
