"""Application settings, loaded once and injected everywhere else."""

from __future__ import annotations

from functools import lru_cache

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Environment-driven configuration.

    Every value has a safe default so the service starts in a degraded but
    functional mode without secrets: forecasting falls back to the statistical
    model when no artefact is present, and the assistant returns a templated
    answer when no OpenAI key is configured. Nothing silently fabricates data.
    """

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    # --- Service ----------------------------------------------------------
    app_name: str = "FIP AI Service"
    app_env: str = "local"
    log_level: str = "INFO"

    # Shared secret checked on every request from the Laravel API. When empty
    # the guard is disabled — acceptable locally, never in production.
    service_token: str = ""

    allowed_origins: str = "http://localhost:3000,http://localhost:8000"

    # --- OpenAI -----------------------------------------------------------
    openai_api_key: str = ""
    openai_model: str = "gpt-4o-mini"
    openai_timeout: int = 45
    openai_max_tokens: int = 800

    # --- OCR --------------------------------------------------------------
    tesseract_cmd: str = "/usr/bin/tesseract"
    ocr_languages: str = "eng"
    ocr_min_confidence: float = 0.60

    # --- Models -----------------------------------------------------------
    model_dir: str = "/app/models"
    forecast_model_version: str = "1.4.0"

    # --- Infrastructure ---------------------------------------------------
    redis_url: str = "redis://redis:6379/2"
    cache_ttl: int = 3600
    database_url: str = ""

    # --- Domain constants -------------------------------------------------
    # Weight given to each regressor when the gradient-boosted model is
    # unavailable and the service falls back to the transparent linear model.
    fallback_weights: dict[str, float] = Field(
        default_factory=lambda: {
            "mops": 0.46,
            "crude": 0.28,
            "fx": 0.18,
            "momentum": 0.08,
        }
    )

    # Philippine pump prices move in 5-centavo steps.
    price_step: float = 0.05

    @property
    def cors_origins(self) -> list[str]:
        return [origin.strip() for origin in self.allowed_origins.split(",") if origin.strip()]

    @property
    def is_production(self) -> bool:
        return self.app_env.lower() in {"production", "prod"}

    @field_validator("log_level")
    @classmethod
    def _normalise_log_level(cls, value: str) -> str:
        level = value.upper()
        if level not in {"DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"}:
            return "INFO"
        return level


@lru_cache
def get_settings() -> Settings:
    """Cached accessor so settings are parsed exactly once per process."""
    return Settings()
