# Playwright's own image, because the browser and its shared libraries are the
# hard part. Building Chromium's dependency list onto a plain python:3.12-slim
# is a long list of apt packages that breaks whenever Playwright bumps a
# version; the official image pins the pair together.
#
# The tag must match the Playwright version in requirements.txt. A mismatch is
# caught at run time by Playwright itself, with a clear message, but it is
# caught in production — so CI builds this image and runs a browser in it.
FROM mcr.microsoft.com/playwright/python:v1.49.0-jammy

LABEL org.opencontainers.image.title="FIP DOE fuel price scraper" \
      org.opencontainers.image.description="Collects DOE fuel prices from the Looker Studio dashboard" \
      org.opencontainers.image.source="https://github.com/nextlevelbuilder/fip"

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    TZ=Asia/Manila

WORKDIR /app

# Dependencies first, as their own layer: application edits are frequent and
# should not reinstall Chromium's Python bindings every build.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# The base image ships the browsers, but only for the version it was built
# with. This is a no-op when they match and a correct install when they do not.
RUN playwright install chromium --with-deps

COPY . .

# Non-root. The base image provides `pwuser`; the writable directories have to
# be handed over explicitly or the first run fails trying to create its log.
RUN mkdir -p /app/logs /app/captures \
    && chown -R pwuser:pwuser /app
USER pwuser

# Exercises the import graph and the settings model without opening a browser
# or touching the database. Catches a bad build, not a bad dashboard.
HEALTHCHECK --interval=5m --timeout=30s --start-period=30s --retries=3 \
    CMD python -c "import scraper, scheduler; from settings import get_settings; get_settings()" || exit 1

# The scheduler, not a single run: the container is a long-lived service that
# wakes at 06:00. `docker compose run --rm scraper python scraper.py` is how a
# one-off is triggered.
CMD ["python", "scheduler.py"]
