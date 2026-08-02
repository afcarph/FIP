"""Contracts for the OCR price-board endpoint."""

from __future__ import annotations

from pydantic import BaseModel, Field


class BoundingBox(BaseModel):
    """Pixel coordinates of the recognised text, for the review overlay."""

    x: int
    y: int
    width: int
    height: int


class OcrLine(BaseModel):
    """One fuel label plus the price found alongside it."""

    label: str | None = None
    fuel_type: str | None = None
    price: float | None = None
    confidence: float = Field(ge=0, le=1)
    bbox: BoundingBox | None = None


class OcrResponse(BaseModel):
    raw_text: str
    lines: list[OcrLine] = Field(default_factory=list)
    engine: str = "tesseract"
    overall_confidence: float = Field(ge=0, le=1)
    processing_ms: int
    warnings: list[str] = Field(default_factory=list)
