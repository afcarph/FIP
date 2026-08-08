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


class ReceiptField(BaseModel):
    """One extracted value, with how much the parser trusts it.

    Confidence is per-field rather than per-receipt because the fields are read
    independently: a receipt can give up its total with certainty and its
    odometer not at all, and collapsing that into one number would hide which
    half a reviewer needs to check.
    """

    value: float | str | None = None
    confidence: float = Field(default=0.0, ge=0, le=1)
    source_text: str | None = None


class ReceiptResponse(BaseModel):
    """A fill-up receipt, read into the fields FIP records for a purchase.

    Deliberately a *draft*: this endpoint reports what it saw and never decides
    that a purchase happened. Laravel owns that, because it knows the vehicle,
    the odometer it must not roll back, and the price band for the station.
    """

    raw_text: str
    litres: ReceiptField = Field(default_factory=ReceiptField)
    price_per_litre: ReceiptField = Field(default_factory=ReceiptField)
    total_cost: ReceiptField = Field(default_factory=ReceiptField)
    purchased_at: ReceiptField = Field(default_factory=ReceiptField)
    odometer: ReceiptField = Field(default_factory=ReceiptField)
    station_hint: ReceiptField = Field(default_factory=ReceiptField)
    fuel_type: ReceiptField = Field(default_factory=ReceiptField)
    engine: str = "tesseract"
    overall_confidence: float = Field(default=0.0, ge=0, le=1)
    processing_ms: int = 0
    warnings: list[str] = Field(default_factory=list)
