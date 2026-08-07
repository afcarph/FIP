"""Daily scheduling.

Runs at 06:00 Asia/Manila by default. The DOE publishes its weekly adjustment
early Tuesday morning; a 06:00 run catches it the same day, and running daily
rather than weekly means a single failure costs one day rather than a week.

Two ways to schedule, and the image supports both:

* ``python scheduler.py`` — a long-lived process holding an APScheduler cron
  trigger. This is what ``docker compose up -d`` starts.
* system cron calling ``python scraper.py`` — for hosts that would rather own
  the schedule. See ``crontab.example``.

Retries live in :func:`scraper.run`, not here. A retry that re-enters through
the scheduler would create a second run log and lose the attempt count.
"""

from __future__ import annotations

import signal
import sys
import threading
from datetime import datetime
from types import FrameType
from zoneinfo import ZoneInfo

from apscheduler.events import EVENT_JOB_ERROR, EVENT_JOB_EXECUTED, JobExecutionEvent
from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger

from database import init_db, last_successful_run
from logger import get_logger
from scraper import run
from settings import get_settings

log = get_logger(__name__)

_shutdown = threading.Event()

JOB_ID = "doe-daily-scrape"


def scheduled_job() -> None:
    """The job body. Never raises.

    An exception escaping here is caught by APScheduler and logged, but the
    job's own error handling is what writes the run log — so failures are
    handled inside :func:`scraper.run` and only genuine bugs reach this
    handler.
    """
    try:
        result = run()
        log.info("Scheduled run finished: %s", result.summary())
    except Exception:
        log.exception("Scheduled run raised")


def _on_job_event(event: JobExecutionEvent) -> None:
    if event.exception:
        log.error("Job %s failed", event.job_id, exc_info=event.exception)
    else:
        log.debug("Job %s completed", event.job_id)


def build_scheduler() -> BackgroundScheduler:
    """A scheduler with the daily trigger installed."""
    settings = get_settings()
    timezone = ZoneInfo(settings.timezone)

    scheduler = BackgroundScheduler(
        timezone=timezone,
        job_defaults={
            # The run takes minutes and fires daily, so an overlap means the
            # previous one hung. Running a second browser alongside it would
            # double the memory on a t3.small and take both down.
            "coalesce": True,
            "max_instances": 1,
            # If the host was asleep or the container was restarting at 06:00,
            # still run — within the hour. Later than that and the next day's
            # scheduled run is closer than the missed one.
            "misfire_grace_time": 3600,
        },
    )

    scheduler.add_job(
        scheduled_job,
        trigger=CronTrigger(
            hour=settings.schedule_hour,
            minute=settings.schedule_minute,
            timezone=timezone,
        ),
        id=JOB_ID,
        name="DOE daily fuel price scrape",
        replace_existing=True,
    )

    scheduler.add_listener(_on_job_event, EVENT_JOB_EXECUTED | EVENT_JOB_ERROR)

    return scheduler


def _handle_signal(signum: int, _frame: FrameType | None) -> None:
    log.info("Received signal %s; shutting down", signal.Signals(signum).name)
    _shutdown.set()


def main(argv: list[str] | None = None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    run_now = "--run-now" in argv

    settings = get_settings()

    init_db()

    last = last_successful_run()
    if last is None:
        log.warning("No successful run on record — this is a fresh install")
    else:
        log.info("Last successful run: %s", last.isoformat())

    scheduler = build_scheduler()
    scheduler.start()

    job = scheduler.get_job(JOB_ID)
    log.info(
        "Scheduled %02d:%02d %s — next run %s",
        settings.schedule_hour,
        settings.schedule_minute,
        settings.timezone,
        job.next_run_time.isoformat() if job and job.next_run_time else "unknown",
    )

    if run_now:
        log.info("--run-now: executing immediately as well")
        scheduler.add_job(
            scheduled_job,
            id=f"{JOB_ID}-immediate",
            next_run_time=datetime.now(ZoneInfo(settings.timezone)),
        )

    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)

    # Block on an event rather than sleeping in a loop, so SIGTERM from
    # `docker stop` is handled immediately instead of after the current sleep.
    _shutdown.wait()

    log.info("Waiting for a running job to finish")
    scheduler.shutdown(wait=True)
    log.info("Scheduler stopped")

    return 0


if __name__ == "__main__":
    sys.exit(main())
