"""Contracts for route optimisation."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class Coordinate(BaseModel):
    lat: float = Field(ge=-90, le=90)
    lng: float = Field(ge=-180, le=180)


class VehicleProfile(BaseModel):
    km_per_litre: float | None = None
    tank_capacity: float | None = None
    vehicle_type: str = "car"
    fuel_type_id: int | None = None


class RouteRequest(BaseModel):
    origin: Coordinate
    destination: Coordinate
    optimize_for: Literal["cost", "time", "fuel", "balanced"] = "cost"
    alternatives: int = Field(default=3, ge=1, le=5)
    vehicle: VehicleProfile | None = None
    avoid_tolls: bool = False


class RouteOption(BaseModel):
    summary: str
    polyline: str | None = None
    distance_km: float
    duration_minutes: float
    has_tolls: bool = False
    toll_cost: float = 0.0
    traffic_level: Literal["light", "moderate", "heavy"] | None = None
    midpoint: Coordinate | None = None


class RouteResponse(BaseModel):
    routes: list[RouteOption]
    provider: str
