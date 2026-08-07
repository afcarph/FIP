"""What a run's status means.

The question each status answers is "does a human need to look at this?", not
"did anything go wrong". The DOE publishes documents this service cannot use —
LPG price sheets, regional layouts not yet handled — and those are rejected on
every single run. A status that reflects them is a status nobody reads.
"""

from __future__ import annotations

from models import ImportRun
from pipeline import PipelineResult


def _result(**counts: object) -> PipelineResult:
    result = PipelineResult()

    for name, value in counts.items():
        setattr(result, name, value)

    return result


class TestRunStatus:
    def test_a_clean_import_is_a_success(self) -> None:
        assert _result(discovered=9, imported=9).status() == ImportRun.STATUS_SUCCESS

    def test_nothing_new_is_no_changes(self) -> None:
        # Everything found was already held. The normal outcome between weekly
        # publications.
        result = _result(discovered=9, imported=0, skipped=9)

        assert result.status() == ImportRun.STATUS_NO_CHANGES

    def test_nothing_new_plus_permanent_rejections_is_still_no_changes(self) -> None:
        # The regression. Two documents — an 11kg LPG sheet and a North Luzon
        # layout — are rejected on every run. Once the week's reports are
        # imported, every subsequent daily run has 0 imports and those 2
        # rejections, and the old logic called that `failed`. The scheduler
        # would have reported failure every morning forever.
        result = _result(
            discovered=9, imported=0, skipped=6, rejections=["lpg.pdf: not a price table"] * 2
        )

        assert result.status() == ImportRun.STATUS_NO_CHANGES

    def test_importing_some_while_rejecting_others_is_partial(self) -> None:
        # Worth seeing. Not worth paging for.
        result = _result(discovered=9, imported=6, skipped=0, rejections=["a.pdf: unreadable"])

        assert result.status() == ImportRun.STATUS_PARTIAL

    def test_rejecting_everything_is_a_failure(self) -> None:
        # Not a quiet week: a layout change, or discovery returning the wrong
        # documents. Nothing was imported and nothing was recognised as held.
        result = _result(discovered=9, imported=0, skipped=0, rejections=["x"] * 9)

        assert result.status() == ImportRun.STATUS_FAILED

    def test_a_run_level_error_with_no_work_done_is_a_failure(self) -> None:
        # Discovery unreachable, storage broken.
        result = _result(
            discovered=0, imported=0, skipped=0, errors=["Discovery found no documents"]
        )

        assert result.status() == ImportRun.STATUS_FAILED

    def test_a_run_level_error_after_work_is_partial(self) -> None:
        result = _result(discovered=9, imported=4, errors=["storage went away"])

        assert result.status() == ImportRun.STATUS_PARTIAL

    def test_a_run_level_error_does_not_fail_a_run_that_skipped_everything(self) -> None:
        # It did its job — it just also hit a problem worth recording.
        result = _result(discovered=9, imported=0, skipped=9, errors=["one page 500'd"])

        assert result.status() == ImportRun.STATUS_PARTIAL

    def test_rejections_and_errors_are_counted_separately(self) -> None:
        # Both reach the run log; only one decides the status.
        result = _result(discovered=9, imported=6, skipped=0, rejections=["a", "b"], errors=[])

        assert "rejected=2" in result.summary()
        assert "errors=0" in result.summary()


class TestDiscoveringNothingIsNotAQuietWeek:
    """Zero candidates from an API that answered is a failure, not calm.

    The DOE's library holds price publications continuously, so a healthy
    first page contains several of them whatever was published this week.
    Finding none means the query came back useless.

    Observed live on 8 August 2026: the CMS document count fell from 14,898 to
    5,470 and climbed back over the following minutes while its search index
    rebuilt. Discovery matched nothing throughout. Under the previous rule
    that run would have been recorded as `no_changes` — the platform quietly
    not updating, and every signal green.
    """

    def test_no_candidates_at_all_is_a_failure(self) -> None:
        result = PipelineResult()
        result.discovered = 0

        assert result.status() == ImportRun.STATUS_FAILED

    def test_candidates_found_but_none_usable_is_still_a_failure(self) -> None:
        result = PipelineResult()
        result.discovered = 9
        result.rejections = ["a.pdf: unreadable"]

        assert result.status() == ImportRun.STATUS_FAILED

    def test_a_genuinely_quiet_week_still_reads_as_no_changes(self) -> None:
        # Documents found and already held. The normal outcome on six mornings
        # out of seven, and it must stay quiet or nobody reads the status.
        result = PipelineResult()
        result.discovered = 9
        result.skipped = 9

        assert result.status() == ImportRun.STATUS_NO_CHANGES
