"""Decoding the wire format."""

from __future__ import annotations

import json

import pytest

from interceptor import (
    CapturedResponse,
    PayloadDecodeError,
    ResponseInterceptor,
    decode_payload,
    strip_json_prefix,
)


class TestStripPrefix:
    def test_it_removes_the_guard(self) -> None:
        assert strip_json_prefix(')]}\'\n{"a":1}') == '{"a":1}'

    def test_it_removes_the_guard_without_a_newline(self) -> None:
        assert strip_json_prefix(')]}\'{"a":1}') == '{"a":1}'

    def test_it_leaves_a_body_that_has_no_guard(self) -> None:
        assert strip_json_prefix('{"a":1}') == '{"a":1}'

    def test_it_handles_a_utf8_bom(self) -> None:
        # Some proxies re-encode the body and prepend a BOM, which makes the
        # guard no longer the first character and json.loads fail on byte zero.
        assert strip_json_prefix('﻿)]}\'\n{"a":1}') == '{"a":1}'


class TestDecodePayload:
    def test_it_decodes_a_guarded_body(self) -> None:
        assert decode_payload(')]}\'\n{"dataResponse":[]}') == {"dataResponse": []}

    def test_it_decodes_bytes(self) -> None:
        assert decode_payload(b')]}\'\n{"a":[1,2]}') == {"a": [1, 2]}

    def test_an_empty_body_raises(self) -> None:
        with pytest.raises(PayloadDecodeError, match="empty"):
            decode_payload(")]}'\n")

    def test_an_html_error_page_raises_with_the_body_in_the_message(self) -> None:
        # Looker answers with HTML when the report has been unpublished. The
        # message has to carry the body, or the failure reads as a parser bug.
        with pytest.raises(PayloadDecodeError, match="did not decode"):
            decode_payload(")]}'\n<html><body>Sign in</body></html>")

    def test_invalid_utf8_raises(self) -> None:
        with pytest.raises(PayloadDecodeError, match="UTF-8"):
            decode_payload(b"\xff\xfe\x00invalid")


class _FakeResponse:
    """Minimal stand-in for a Playwright Response."""

    def __init__(self, url: str, body: bytes, status: int = 200) -> None:
        self.url = url
        self.status = status
        self._body = body

    def body(self) -> bytes:
        if self._body is None:  # pragma: no cover - guarded by the caller
            raise RuntimeError("body unavailable")
        return self._body


class _UnreadableResponse(_FakeResponse):
    def body(self) -> bytes:
        raise RuntimeError("Response body is unavailable for redirect responses")


def _guarded(payload: object) -> bytes:
    return b")]}'\n" + json.dumps(payload).encode()


class TestResponseInterceptor:
    def test_it_files_responses_by_endpoint(self) -> None:
        interceptor = ResponseInterceptor()

        interceptor._on_response(
            _FakeResponse("https://x/batchedDataV2?x=1", _guarded({"dataResponse": []}))
        )
        interceptor._on_response(_FakeResponse("https://x/getSchema?x=1", _guarded({"fields": []})))
        interceptor._on_response(_FakeResponse("https://x/getReport?x=1", _guarded({"report": {}})))

        assert len(interceptor.data_responses) == 1
        assert len(interceptor.schema_responses) == 1
        assert len(interceptor.report_responses) == 1

    def test_it_ignores_unrelated_traffic(self) -> None:
        interceptor = ResponseInterceptor()

        interceptor._on_response(_FakeResponse("https://x/analytics.js", b"var x=1"))
        interceptor._on_response(_FakeResponse("https://fonts.gstatic.com/f.woff2", b""))

        assert not interceptor.has_data
        assert interceptor.failures == []

    def test_an_undecodable_body_is_recorded_and_does_not_raise(self) -> None:
        # The handler runs inside Playwright's event loop. An exception here
        # tears down the page and costs every response still in flight.
        interceptor = ResponseInterceptor()

        interceptor._on_response(_FakeResponse("https://x/batchedDataV2", b")]}'\nnot json"))

        assert not interceptor.has_data
        assert len(interceptor.failures) == 1
        assert "did not decode" in interceptor.failures[0]

    def test_an_unreadable_body_is_recorded_and_does_not_raise(self) -> None:
        interceptor = ResponseInterceptor()

        interceptor._on_response(_UnreadableResponse("https://x/batchedDataV2", b""))

        assert not interceptor.has_data
        assert "Could not read body" in interceptor.failures[0]

    def test_summary_reports_what_was_captured(self) -> None:
        interceptor = ResponseInterceptor()
        interceptor.data_responses.append(CapturedResponse("u", "batchedDataV2", 200, {}, 10))

        assert "data=1" in interceptor.summary()
