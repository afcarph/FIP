# =============================================================================
#  FIP AI Service — FastAPI + Tesseract
#
#  Prophet compiles a Stan model at install time, which is slow and needs a
#  toolchain. Building wheels in a separate stage keeps that weight out of the
#  runtime image.
# =============================================================================

FROM python:3.11-slim AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential gcc g++ python3-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

COPY requirements.txt .
RUN pip install --no-cache-dir --upgrade pip \
    && pip wheel --no-cache-dir --wheel-dir /wheels -r requirements.txt

# --- runtime ------------------------------------------------------------------
FROM python:3.11-slim

# Tesseract plus the OpenCV runtime libraries. `-headless` in requirements
# avoids the full GUI stack, but libGL and glib are still needed.
RUN apt-get update && apt-get install -y --no-install-recommends \
        tesseract-ocr tesseract-ocr-eng \
        libgl1 libglib2.0-0 libgomp1 \
        curl tzdata \
    && rm -rf /var/lib/apt/lists/*

ENV TZ=Asia/Manila \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    TESSERACT_CMD=/usr/bin/tesseract \
    MODEL_DIR=/app/models

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

WORKDIR /app

COPY --from=builder /wheels /wheels
RUN pip install --no-cache-dir --no-index --find-links=/wheels /wheels/* \
    && rm -rf /wheels

COPY app ./app
RUN mkdir -p /app/models

# Never run the service as root — an OCR pipeline processes untrusted images.
RUN useradd --create-home --shell /bin/bash fip \
    && chown -R fip:fip /app
USER fip

EXPOSE 8001

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 \
    CMD curl -fsS http://localhost:8001/health || exit 1

# One worker per container; scale by replicas so each keeps its own model
# artefacts in memory without duplicating them inside a process.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1"]
