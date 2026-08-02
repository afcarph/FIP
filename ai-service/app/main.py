"""FIP AI service — FastAPI application entrypoint."""

from __future__ import annotations

import time
import uuid
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from prometheus_client import CONTENT_TYPE_LATEST, Counter, Histogram, generate_latest
from starlette.responses import Response

from app.api.v1 import api_router
from app.core.config import get_settings
from app.core.logging import configure_logging, get_logger, request_id_ctx

settings = get_settings()
configure_logging(settings.log_level, json_output=settings.is_production)
logger = get_logger("fip.ai.main")

REQUEST_COUNT = Counter(
    "fip_ai_requests_total",
    "Requests handled by the AI service",
    ["method", "endpoint", "status"],
)

REQUEST_LATENCY = Histogram(
    "fip_ai_request_duration_seconds",
    "Request latency",
    ["endpoint"],
    # Buckets chosen around the real shape: OCR and LLM calls are seconds,
    # everything else is milliseconds.
    buckets=(0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0),
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Warm the heavy services at boot so the first request is not the slow one."""
    logger.info("ai_service_starting", env=settings.app_env, model_dir=settings.model_dir)

    from app.services.forecast_service import get_forecast_service
    from app.services.fraud_service import get_fraud_service
    from app.services.ocr_service import get_ocr_service

    get_forecast_service()
    get_fraud_service()
    get_ocr_service()

    logger.info("ai_service_ready")

    yield

    logger.info("ai_service_stopping")


app = FastAPI(
    title="FIP AI Service",
    description=(
        "Forecasting, OCR, conversational advice, anomaly detection and route "
        "optimisation for the Fuel Intelligence Platform. Internal service: "
        "callers authenticate with a shared X-Service-Token header."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins,
    allow_credentials=True,
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["*"],
)


@app.middleware("http")
async def observability_middleware(request: Request, call_next):
    """Propagate the correlation ID and record timing for every request."""
    request_id = request.headers.get("X-Request-Id") or str(uuid.uuid4())
    request_id_ctx.set(request_id)

    started = time.perf_counter()

    try:
        response = await call_next(request)
    except Exception as exc:  # noqa: BLE001
        duration = time.perf_counter() - started

        logger.exception(
            "unhandled_exception",
            path=request.url.path,
            method=request.method,
            duration_ms=int(duration * 1000),
        )

        REQUEST_COUNT.labels(request.method, request.url.path, 500).inc()
        REQUEST_LATENCY.labels(request.url.path).observe(duration)

        # Never leak an internal traceback across the service boundary.
        return JSONResponse(
            status_code=500,
            content={
                "detail": "The AI service encountered an internal error.",
                "request_id": request_id,
            },
            headers={"X-Request-Id": request_id},
        )

    duration = time.perf_counter() - started

    REQUEST_COUNT.labels(request.method, request.url.path, response.status_code).inc()
    REQUEST_LATENCY.labels(request.url.path).observe(duration)

    response.headers["X-Request-Id"] = request_id
    response.headers["X-Response-Time"] = f"{int(duration * 1000)}ms"

    return response


app.include_router(api_router)


@app.get("/health", tags=["system"], summary="Liveness and capability probe")
async def health() -> dict:
    """Report which capabilities are actually available in this deployment.

    The Laravel admin console surfaces this, so an operator can see at a glance
    whether the assistant is running on the language model or the fallback, and
    whether a trained forecast artefact has been loaded.
    """
    from app.services.forecast_service import get_forecast_service

    forecast_service = get_forecast_service()

    return {
        "status": "ok",
        "service": "fip-ai",
        "version": app.version,
        "environment": settings.app_env,
        "capabilities": {
            "prophet": forecast_service._prophet_available,
            "trained_booster": forecast_service._booster is not None,
            "openai": bool(settings.openai_api_key),
            "ocr": True,
        },
    }


@app.get("/metrics", tags=["system"], include_in_schema=False)
async def metrics() -> Response:
    """Prometheus scrape endpoint."""
    return Response(content=generate_latest(), media_type=CONTENT_TYPE_LATEST)


@app.get("/", include_in_schema=False)
async def root() -> dict:
    return {"service": "fip-ai", "docs": "/docs", "health": "/health"}
