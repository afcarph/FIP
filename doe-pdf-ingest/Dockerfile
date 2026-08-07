# Python plus a JVM. The JVM is only for tabula, the last-resort extractor —
# camelot and pdfplumber are pure Python. It is included because a fallback
# that is absent is not a fallback, and the image is the one place to
# guarantee it exists.
#
# Playwright is gone. The Looker dashboard it drove was backed by a dataset
# last refreshed in 2021; the source is now the DOE's own weekly PDFs, and
# nothing here starts a browser.
FROM python:3.12-slim-bookworm

LABEL org.opencontainers.image.title="FIP DOE PDF ingest" \
      org.opencontainers.image.description="Ingests DOE fuel price monitoring PDFs into FIP" \
      org.opencontainers.image.source="https://github.com/nextlevelbuilder/fip"

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    TZ=Asia/Manila

# ghostscript and the poppler tools are camelot's runtime dependencies;
# default-jre-headless is tabula's. libgl1 is opencv's, which camelot's lattice
# mode uses to find ruling lines.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ghostscript \
        poppler-utils \
        default-jre-headless \
        libgl1 \
        libglib2.0-0 \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Dependencies first, as their own layer: application edits are frequent and
# should not reinstall pandas every build.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Non-root, with the writable directories handed over explicitly — otherwise
# the first run fails trying to create its log.
RUN useradd --create-home --uid 10001 ingest \
    && mkdir -p /app/logs /app/storage/pdfs \
    && chown -R ingest:ingest /app
USER ingest

# Exercises the import graph and the settings model without touching the
# network or the database. Catches a bad build, not a bad publication.
HEALTHCHECK --interval=5m --timeout=30s --start-period=20s --retries=3 \
    CMD python -c "import pipeline, scheduler; from settings import get_settings; get_settings()" || exit 1

# The scheduler, not a single run: the container is a long-lived service that
# wakes at 06:00. `docker compose run --rm ingest python pipeline.py` triggers
# a one-off.
CMD ["python", "scheduler.py"]
