"""What a run records about itself.

The counters and timings exist so that a run which got slower, or started
rejecting documents, can be diagnosed without re-running it. That only works
if what is written is right, so these tests cover the writing rather than the
reading.
"""

from __future__ import annotations

import inspect
import json

from pipeline import PHASES, PipelineResult, process_pdf, replay


class TestPhaseTiming:
    def test_every_phase_starts_at_zero(self) -> None:
        result = PipelineResult()

        assert set(result.phase_ms) == set(PHASES)
        assert all(value == 0 for value in result.phase_ms.values())

    def test_timing_accumulates_across_documents(self) -> None:
        # Phases are entered once per document, so they have to add up rather
        # than record only the last one.
        result = PipelineResult()

        for _ in range(3):
            with result.timing("extraction"):
                pass

        assert result.phase_ms["extraction"] >= 0
        assert result.phase_ms["download"] == 0

    def test_a_phase_that_raises_is_still_counted(self) -> None:
        # Otherwise the phase that fails is the one phase with no timing, and
        # a run that got slower because downloads started timing out would
        # report downloads as instant.
        result = PipelineResult()

        try:
            with result.timing("download"):
                raise RuntimeError("boom")
        except RuntimeError:
            pass

        assert "download" in result.phase_ms


class TestTheRunLogFields:
    def test_it_reports_every_counter_the_schema_holds(self) -> None:
        result = PipelineResult()
        result.discovered = 9
        result.downloaded = 1
        result.reports_parsed = 3
        result.imported = 1
        result.skipped = 6
        result.records = 770
        result.replaced = 2
        result.rejections = ["a.pdf: no region", "b.pdf: below threshold"]
        result.total_ms = 61711

        fields = result.run_log_fields()

        assert fields["pdfs_discovered"] == 9
        assert fields["reports_discovered"] == 3
        assert fields["reports_imported"] == 1
        assert fields["reports_skipped"] == 6
        assert fields["reports_rejected"] == 2
        assert fields["records_imported"] == 770
        assert fields["records_updated"] == 2
        assert fields["total_duration_ms"] == 61711

    def test_reports_discovered_is_not_the_same_as_pdfs_discovered(self) -> None:
        # Discovery matches on title, and the DOE publishes LPG sheets and
        # circulars whose titles match. Conflating the two would report a
        # healthy parse rate on a run that parsed almost nothing.
        result = PipelineResult()
        result.discovered = 9
        result.reports_parsed = 3

        fields = result.run_log_fields()

        assert fields["pdfs_discovered"] != fields["reports_discovered"]

    def test_parser_versions_are_recorded_as_json(self) -> None:
        result = PipelineResult()
        result.parser_versions["pdfplumber-coordinates"] += 2
        result.parser_versions["camelot"] += 1

        decoded = json.loads(str(result.run_log_fields()["parser_versions"]))

        assert decoded == {"camelot": 1, "pdfplumber-coordinates": 2}

    def test_no_parser_means_null_not_an_empty_object(self) -> None:
        # "{}" would read as "we recorded which parsers ran, and none did".
        assert PipelineResult().run_log_fields()["parser_versions"] is None

    def test_graphql_pages_come_from_the_provider(self) -> None:
        result = PipelineResult()
        result.discovery_stats = {"graphql_pages": 1, "documents_scanned": 100}

        assert result.run_log_fields()["graphql_pages"] == 1

    def test_a_provider_that_reports_nothing_yields_null_pages(self) -> None:
        # A sitemap provider has no notion of a GraphQL page, and inventing 0
        # for it would read as "walked no pages".
        assert PipelineResult().run_log_fields()["graphql_pages"] is None

    def test_an_unmeasured_total_stays_null(self) -> None:
        assert PipelineResult().run_log_fields()["total_duration_ms"] is None


class TestReplayStoresOnlyWhatValidationAccepted:
    """The rule the scheduled path applies, applied here too.

    `process_pdf` narrows the report to `validation.accepted` before storing.
    `replay` did not, so every row the validator rejected as implausible was
    stored anyway — counted as rejected in the log and present in the
    database. A ₱833.60 litre of RON 95 reached staging that way.
    """

    def test_the_scheduled_path_and_the_replay_path_agree(self) -> None:
        # Both must narrow to what validation accepted. Asserted on the source
        # because the alternative is a database round trip to prove a one-line
        # omission, and this is the omission that keeps recurring.
        assert "validation.accepted" in inspect.getsource(process_pdf)
        assert "validation.accepted" in inspect.getsource(replay)
