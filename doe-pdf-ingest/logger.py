"""Logging setup.

One process-wide configuration, applied once. Every module takes a logger from
:func:`get_logger` rather than calling ``basicConfig``, so importing the parser
in a test does not reconfigure the root logger out from under the test runner.

Runs are correlated by a ``run_id`` injected into every record. Without it,
concurrent retries interleave in the log file and there is no way to tell which
attempt produced which error.
"""

from __future__ import annotations

import json
import logging
import logging.handlers
import sys
import uuid
from contextvars import ContextVar
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from settings import get_settings

_current_run_id: ContextVar[str] = ContextVar("run_id", default="-")
_configured = False


class _RunIdFilter(logging.Filter):
    """Attach the active run id to every record."""

    def filter(self, record: logging.LogRecord) -> bool:
        record.run_id = _current_run_id.get()
        return True


class _JsonFormatter(logging.Formatter):
    """One JSON object per line, for shipping to a log aggregator."""

    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, Any] = {
            "ts": datetime.fromtimestamp(record.created, tz=UTC).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "run_id": getattr(record, "run_id", "-"),
            "message": record.getMessage(),
        }

        if record.exc_info:
            payload["exception"] = self.formatException(record.exc_info)

        # Anything passed via `extra=` that is not a standard LogRecord
        # attribute. This is how modules attach counts and urls.
        for key, value in record.__dict__.items():
            if key not in _RESERVED and not key.startswith("_"):
                payload[key] = value

        return json.dumps(payload, default=str, ensure_ascii=False)


_RESERVED = frozenset(logging.LogRecord("", 0, "", 0, "", None, None).__dict__) | {
    "run_id",
    "message",
    "asctime",
    "taskName",
}


def configure_logging() -> None:
    """Install handlers on the root logger. Safe to call more than once."""
    global _configured
    if _configured:
        return

    settings = get_settings()
    settings.log_dir.mkdir(parents=True, exist_ok=True)

    root = logging.getLogger()
    root.setLevel(settings.log_level)
    root.handlers.clear()

    run_filter = _RunIdFilter()

    if settings.log_json:
        formatter: logging.Formatter = _JsonFormatter()
    else:
        formatter = logging.Formatter(
            "%(asctime)s %(levelname)-8s [%(run_id)s] %(name)s: %(message)s",
            datefmt="%Y-%m-%d %H:%M:%S",
        )

    # stdout, so `docker logs` and systemd both see it.
    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    stream.addFilter(run_filter)
    root.addHandler(stream)

    # A rotating file as well: the container's log driver is not always
    # configured, and a failed 06:00 run is usually investigated hours later.
    file_handler = logging.handlers.TimedRotatingFileHandler(
        filename=settings.log_dir / "scraper.log",
        when="midnight",
        backupCount=30,
        encoding="utf-8",
    )
    file_handler.setFormatter(formatter)
    file_handler.addFilter(run_filter)
    root.addHandler(file_handler)

    # Playwright logs every frame navigation at INFO, which buries ours.
    logging.getLogger("asyncio").setLevel(logging.WARNING)
    logging.getLogger("urllib3").setLevel(logging.WARNING)

    _configured = True


def get_logger(name: str) -> logging.Logger:
    """A configured logger for ``name``."""
    configure_logging()
    return logging.getLogger(name)


def new_run_id() -> str:
    """Start a new correlation id and make it current."""
    run_id = uuid.uuid4().hex[:12]
    _current_run_id.set(run_id)
    return run_id


def current_run_id() -> str:
    """The correlation id for the run in progress."""
    return _current_run_id.get()


def capture_path(name: str) -> Path | None:
    """Path for a raw payload capture, or ``None`` if capturing is off.

    Raw payloads are the difference between diagnosing a schema change in ten
    minutes and waiting a day for the next run to reproduce it.
    """
    settings = get_settings()
    if not settings.capture_payloads:
        return None

    directory = settings.capture_dir / datetime.now(tz=UTC).strftime("%Y-%m-%d")
    directory.mkdir(parents=True, exist_ok=True)
    return directory / f"{current_run_id()}-{name}"


def prune_captures() -> int:
    """Delete captures older than the retention window. Returns the count."""
    settings = get_settings()
    if not settings.capture_dir.exists():
        return 0

    cutoff = datetime.now(tz=UTC).timestamp() - (settings.capture_retention_days * 86_400)
    removed = 0

    for day_dir in sorted(settings.capture_dir.iterdir()):
        if not day_dir.is_dir():
            continue
        if day_dir.stat().st_mtime >= cutoff:
            continue
        for item in day_dir.iterdir():
            item.unlink()
            removed += 1
        day_dir.rmdir()

    return removed
