"""Contracts for predictive maintenance."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field


class ScheduleItem(BaseModel):
    id: int
    code: str | None = None
    interval_km: int | None = None
    interval_days: int | None = None
    last_performed_at: date | None = None
    last_odometer: float | None = None
    due_at: date | None = None
    due_odometer: float | None = None


class UsageProfile(BaseModel):
    window_days: int = 90
    distance_km: float = 0.0
    avg_km_per_day: float = 0.0
    fill_ups: int = 0
    avg_km_per_litre: float | None = None


class MaintenanceRequest(BaseModel):
    vehicle: dict
    usage: UsageProfile
    schedules: list[ScheduleItem] = Field(default_factory=list)
    history: list[dict] = Field(default_factory=list)


class MaintenancePrediction(BaseModel):
    schedule_id: int
    predicted_due_at: date
    confidence: float = Field(ge=0, le=1)
    reason: str
    days_earlier_than_rule: int | None = None


class MaintenanceResponse(BaseModel):
    vehicle_id: int
    predictions: list[MaintenancePrediction]
    model_used: str
