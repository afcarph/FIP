"""Request/response contracts for the forecasting endpoints."""

from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import BaseModel, Field, field_validator

Direction = Literal["increase", "rollback", "no_change"]


class PricePoint(BaseModel):
    """One observation in the national daily average series."""

    date: date
    avg_price: float = Field(gt=0, lt=1000)
    min_price: float | None = None
    max_price: float | None = None
    samples: int | None = None


class AdvisoryPoint(BaseModel):
    """A realised weekly DOE adjustment — the model's target history."""

    week_start: date
    change_amount: float
    direction: Direction
    effective_at: str | None = None
    notes: str | None = None


class IndicatorPoint(BaseModel):
    date: date
    value: float


class PriceForecastRequest(BaseModel):
    fuel_type: str = Field(min_length=2, max_length=32)
    fuel_category: Literal["gasoline", "diesel", "lpg", "ev", "cng"] = "gasoline"
    forecast_for: date
    horizon_weeks: int = Field(default=4, ge=1, le=12)

    history: list[PricePoint] = Field(default_factory=list)
    advisories: list[AdvisoryPoint] = Field(default_factory=list)
    # indicator name -> series, e.g. {"dubai_crude": [...], "usd_php": [...]}
    indicators: dict[str, list[IndicatorPoint]] = Field(default_factory=dict)

    @field_validator("history")
    @classmethod
    def _sort_history(cls, points: list[PricePoint]) -> list[PricePoint]:
        return sorted(points, key=lambda p: p.date)


class ForecastDriver(BaseModel):
    """One attributed contributor to the prediction, for the "why" panel."""

    factor: str
    weight: float = Field(ge=0, le=1)
    value: str
    direction: Literal["up", "down", "flat"] = "flat"


class PriceForecastResponse(BaseModel):
    fuel_type: str
    forecast_for: date
    direction: Direction
    change_amount: float
    predicted_price: float | None = None
    lower_bound: float | None = None
    upper_bound: float | None = None
    confidence: float = Field(ge=0, le=1)
    drivers: list[ForecastDriver] = Field(default_factory=list)
    narrative: str
    model_used: str
    horizon: list[dict] = Field(default_factory=list)


class ConsumptionRequest(BaseModel):
    vehicle_id: int
    vehicle_type: str = "car"
    year: int | None = None
    tank_capacity: float | None = None
    baseline_km_per_litre: float | None = None
    # Recent fill-ups, oldest first.
    history: list[dict] = Field(default_factory=list)
    horizon_days: int = Field(default=30, ge=7, le=365)


class ConsumptionResponse(BaseModel):
    vehicle_id: int
    predicted_litres: float
    predicted_cost: float | None = None
    predicted_distance_km: float | None = None
    predicted_km_per_litre: float | None = None
    confidence: float
    trend: Literal["improving", "stable", "degrading"]
    factors: list[str] = Field(default_factory=list)
    model_used: str


class DemandRequest(BaseModel):
    region_id: int | None = None
    fuel_type: str
    history: list[PricePoint] = Field(default_factory=list)
    volume_history: list[dict] = Field(default_factory=list)
    horizon_days: int = Field(default=14, ge=1, le=90)


class DemandResponse(BaseModel):
    fuel_type: str
    region_id: int | None
    forecast: list[dict]
    confidence: float
    model_used: str
