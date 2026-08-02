"""Prediction endpoints: price forecast, consumption, maintenance, demand."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.logging import get_logger
from app.core.security import verify_service_token
from app.schemas.forecast import (
    ConsumptionRequest,
    ConsumptionResponse,
    DemandRequest,
    DemandResponse,
    PriceForecastRequest,
    PriceForecastResponse,
)
from app.schemas.maintenance import MaintenanceRequest, MaintenanceResponse
from app.services.forecast_service import ForecastService, get_forecast_service
from app.services.prediction_service import PredictionService, get_prediction_service

router = APIRouter(prefix="/predict", tags=["predictions"], dependencies=[Depends(verify_service_token)])
logger = get_logger(__name__)


@router.post("/price", response_model=PriceForecastResponse, summary="Weekly pump price forecast")
async def forecast_price(
    request: PriceForecastRequest,
    service: ForecastService = Depends(get_forecast_service),
) -> PriceForecastResponse:
    """Predict the coming week's DOE adjustment for one fuel type.

    Returns the direction, the peso-per-litre change, a confidence figure and
    the ranked drivers behind the call, so the client can show *why* rather
    than just *what*.
    """
    logger.info(
        "forecast_requested",
        fuel_type=request.fuel_type,
        history_points=len(request.history),
        indicators=list(request.indicators.keys()),
    )

    response = service.forecast(request)

    logger.info(
        "forecast_produced",
        fuel_type=request.fuel_type,
        direction=response.direction,
        change=response.change_amount,
        confidence=response.confidence,
        model=response.model_used,
    )

    return response


@router.post("/consumption", response_model=ConsumptionResponse, summary="Vehicle consumption projection")
async def predict_consumption(
    request: ConsumptionRequest,
    service: PredictionService = Depends(get_prediction_service),
) -> ConsumptionResponse:
    """Project litres, distance and cost for one vehicle over a horizon."""
    return service.predict_consumption(request)


@router.post("/maintenance", response_model=MaintenanceResponse, summary="Predictive maintenance timing")
async def predict_maintenance(
    request: MaintenanceRequest,
    service: PredictionService = Depends(get_prediction_service),
) -> MaintenanceResponse:
    """Estimate when each service item will actually fall due, given usage."""
    return service.predict_maintenance(request)


@router.post("/demand", response_model=DemandResponse, summary="Regional demand forecast")
async def forecast_demand(
    request: DemandRequest,
    service: PredictionService = Depends(get_prediction_service),
) -> DemandResponse:
    """Project regional fuel demand for station stock planning."""
    return service.forecast_demand(request)
