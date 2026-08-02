"""OCR endpoint for photographed price boards."""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile, status

from app.core.logging import get_logger
from app.core.security import verify_service_token
from app.schemas.ocr import OcrResponse
from app.services.ocr_service import OcrService, get_ocr_service

router = APIRouter(prefix="/ocr", tags=["ocr"], dependencies=[Depends(verify_service_token)])
logger = get_logger(__name__)

MAX_UPLOAD_BYTES = 10 * 1024 * 1024
ACCEPTED_TYPES = {"image/jpeg", "image/png", "image/webp", "image/heic", "image/heif"}


@router.post("/price-board", response_model=OcrResponse, summary="Extract prices from a board photo")
async def scan_price_board(
    file: UploadFile = File(...),
    service: OcrService = Depends(get_ocr_service),
) -> OcrResponse:
    """Read fuel labels and prices from a photograph of a station price board.

    Returns every recognised line with its own confidence and bounding box.
    Validation against the local price band happens in the Laravel API, which
    knows the station's context; this endpoint only reports what it saw.
    """
    if file.content_type not in ACCEPTED_TYPES:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            detail=f"Unsupported image type [{file.content_type}].",
        )

    contents = await file.read()

    if len(contents) > MAX_UPLOAD_BYTES:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail="Image exceeds the 10 MB limit.",
        )

    if not contents:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Empty upload.")

    response = service.scan(contents)

    logger.info(
        "ocr_completed",
        filename=file.filename,
        bytes=len(contents),
        lines=len(response.lines),
        confidence=response.overall_confidence,
        ms=response.processing_ms,
    )

    return response
