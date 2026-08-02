"""Contracts for anomaly detection over fuel transactions."""

from __future__ import annotations

from pydantic import BaseModel, Field


class Transaction(BaseModel):
    id: int
    vehicle_id: int | None = None
    driver_id: int | None = None
    litres: float
    price_per_litre: float
    total_cost: float
    odometer: float | None = None
    distance_since_last: float | None = None
    km_per_litre: float | None = None
    tank_capacity: float | None = None
    baseline_km_per_litre: float | None = None
    purchased_at: str
    latitude: float | None = None
    longitude: float | None = None


class FraudRequest(BaseModel):
    transactions: list[Transaction] = Field(min_length=1, max_length=5000)
    contamination: float = Field(default=0.05, ge=0.001, le=0.3)


class FraudResult(BaseModel):
    id: int
    anomaly_score: float = Field(ge=0, le=1)
    alert_type: str
    contributing_features: list[dict] = Field(default_factory=list)


class FraudResponse(BaseModel):
    results: list[FraudResult]
    model_used: str
    samples_scored: int
