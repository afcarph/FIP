"""Environment-driven settings.

Carried over from the Looker scraper — the database, logging, scheduling and
retry knobs were never specific to that source and are reused as-is. What
changed is everything about *where* the data comes from: the browser settings
are gone, and discovery, download and extraction settings replace them.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from urllib.parse import quote_plus

from pydantic import Field, computed_field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

BASE_DIR = Path(__file__).resolve().parent


class Settings(BaseSettings):
    """Runtime configuration, read from the environment or a local ``.env``."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        env_prefix="DOE_",
        extra="ignore",
    )

    # --- database -----------------------------------------------------------
    #
    # The platform's own database, not a separate one. The ingest writes
    # `fuel_reports`, `fuel_prices` and the run log; Laravel reads them. There
    # is no second copy of anything the platform already stores.
    db_host: str = Field(default="127.0.0.1")
    db_port: int = Field(default=3306)
    db_name: str = Field(default="fip")
    db_user: str = Field(default="fip")
    db_password: str = Field(default="")
    db_charset: str = Field(default="utf8mb4")

    db_pool_recycle: int = Field(default=3600)
    db_pool_pre_ping: bool = Field(default=True)
    db_echo: bool = Field(default=False)

    # --- discovery ----------------------------------------------------------
    #
    # The article listing, not the CMS. PDFs live on prod-cms.doe.gov.ph but
    # are only *linked* from doe.gov.ph articles, and the CMS exposes no index.
    portal_base_url: str = Field(default="https://doe.gov.ph")
    cms_base_url: str = Field(default="https://prod-cms.doe.gov.ph")

    #: Listing pages to crawl, as query strings against
    #: /articles/group/liquid-fuels. Price Monitoring carries the regional
    #: tables; Oil Monitor carries the weekly national summary.
    listing_categories: list[str] = Field(default=["Price Monitoring", "Oil Monitor"])

    #: How many listing pages back to walk on a normal run. One page covers
    #: roughly a fortnight, so two is enough to catch a week the scheduler
    #: missed without re-reading years of archive every morning.
    listing_pages: int = Field(default=2, ge=1)

    #: Backfill depth, used only by `--backfill`.
    backfill_pages: int = Field(default=168, ge=1)

    #: Most candidates a normal run will process.
    #:
    #: The listing pages embed the *whole* archive in their cards — one page
    #: yields around 1,800 documents going back years, not the fortnight the
    #: pagination implies. Unbounded, every morning would re-download the lot.
    #: The listing is ordered newest first, so the cap keeps a daily run to
    #: what is plausibly new; `--backfill` lifts it.
    max_candidates_per_run: int = Field(default=40, ge=1)

    # --- download -----------------------------------------------------------
    download_dir: Path = Field(default=BASE_DIR / "storage" / "pdfs")
    request_timeout_s: int = Field(default=60, ge=5)
    #: Between requests to doe.gov.ph. This is a public agency's server and a
    #: daily job has no reason to hurry.
    request_delay_s: float = Field(default=1.0, ge=0)
    user_agent: str = Field(
        default=(
            "FIP-DOE-Ingest/1.0 (+https://github.com/nextlevelbuilder/fip; fuel price monitoring)"
        )
    )
    #: A price monitoring PDF is ~130KB. Anything far larger is not one of
    #: these documents and should not be pulled into memory to find out.
    max_pdf_bytes: int = Field(default=25 * 1024 * 1024)

    # --- extraction ---------------------------------------------------------
    #: Tried in order until one clears `min_extraction_quality`.
    extractors: list[str] = Field(default=["camelot", "pdfplumber", "tabula"])
    #: Below this an extraction is rejected and the next extractor is tried.
    #: See extractor.score_table for what is measured.
    min_extraction_quality: float = Field(default=0.55, ge=0.0, le=1.0)
    #: tabula needs a JVM. Where there is none, skip it rather than crash the
    #: run on the last fallback.
    enable_tabula: bool = Field(default=True)

    # --- validation ---------------------------------------------------------
    #: Pesos per litre. The DOE publishes premium grades near ₱140 and the
    #: cheapest independents near ₱60, so the window is deliberately wide — its
    #: job is to catch a column of volumes or a misread decimal, not to second
    #: guess the department.
    min_plausible_price: float = Field(default=10.0)
    max_plausible_price: float = Field(default=400.0)
    #: A report whose rows are mostly unusable is a layout change, not a quiet
    #: data problem. Below this share of valid rows the run fails loudly.
    min_valid_row_ratio: float = Field(default=0.5, ge=0.0, le=1.0)

    # --- run behaviour ------------------------------------------------------
    max_attempts: int = Field(default=3, ge=1)
    retry_base_delay_s: float = Field(default=5.0, ge=0)
    schedule_hour: int = Field(default=6, ge=0, le=23)
    schedule_minute: int = Field(default=0, ge=0, le=59)
    timezone: str = Field(default="Asia/Manila")

    # --- notification -------------------------------------------------------
    #: Optional webhook, posted to when a run imports a new report or fails.
    #: Empty disables it; the run log is still written either way.
    notify_webhook_url: str = Field(default="")

    # --- observability ------------------------------------------------------
    log_level: str = Field(default="INFO")
    log_json: bool = Field(default=False)
    log_dir: Path = Field(default=BASE_DIR / "logs")
    #: Original PDFs are kept indefinitely by default. They are the source of
    #: truth for a re-extraction, they are ~130KB each, and the DOE does not
    #: keep an accessible archive of superseded weeks.
    pdf_retention_days: int = Field(default=0, ge=0)

    @field_validator("log_level")
    @classmethod
    def _upper(cls, value: str) -> str:
        return value.upper()

    @computed_field  # type: ignore[prop-decorator]
    @property
    def database_url(self) -> str:
        """SQLAlchemy URL.

        The password is quoted because a generated MySQL password routinely
        contains ``@`` or ``/``, which silently truncate an unquoted DSN into a
        connection to the wrong host.
        """
        password = quote_plus(self.db_password)
        return (
            f"mysql+pymysql://{self.db_user}:{password}"
            f"@{self.db_host}:{self.db_port}/{self.db_name}"
            f"?charset={self.db_charset}"
        )

    def safe_database_url(self) -> str:
        """The DSN with the password removed, for logs."""
        return f"mysql+pymysql://{self.db_user}:***@{self.db_host}:{self.db_port}/{self.db_name}"

    def listing_url(self, category: str, page: int = 1) -> str:
        """A listing page for one category."""
        suffix = f"&page={page}" if page > 1 else ""
        return (
            f"{self.portal_base_url}/articles/group/liquid-fuels"
            f"?category={category.replace(' ', '+')}&display_type=Card{suffix}"
        )


@lru_cache
def get_settings() -> Settings:
    """Cached settings singleton."""
    return Settings()
