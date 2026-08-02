"""Structured JSON logging with request correlation."""

from __future__ import annotations

import logging
import sys
from contextvars import ContextVar

import structlog

# Propagated from the X-Request-Id header so a single user action can be traced
# across the Laravel API and this service.
request_id_ctx: ContextVar[str] = ContextVar("request_id", default="-")


def _add_request_id(_logger, _method, event_dict: dict) -> dict:
    event_dict["request_id"] = request_id_ctx.get()
    return event_dict


def configure_logging(level: str = "INFO", json_output: bool = True) -> None:
    """Install structlog over the stdlib logger.

    JSON in production so log aggregation can parse it; a coloured console
    renderer locally where a human is reading.
    """
    logging.basicConfig(format="%(message)s", stream=sys.stdout, level=level)

    processors = [
        structlog.contextvars.merge_contextvars,
        structlog.stdlib.add_log_level,
        structlog.stdlib.add_logger_name,
        _add_request_id,
        structlog.processors.TimeStamper(fmt="iso", utc=True),
        structlog.processors.StackInfoRenderer(),
        structlog.processors.format_exc_info,
    ]

    processors.append(
        structlog.processors.JSONRenderer()
        if json_output
        else structlog.dev.ConsoleRenderer(colors=True)
    )

    structlog.configure(
        processors=processors,
        wrapper_class=structlog.make_filtering_bound_logger(getattr(logging, level, logging.INFO)),
        logger_factory=structlog.stdlib.LoggerFactory(),
        cache_logger_on_first_use=True,
    )


def get_logger(name: str = "fip.ai"):
    return structlog.get_logger(name)
