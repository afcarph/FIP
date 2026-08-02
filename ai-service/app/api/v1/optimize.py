"""Route optimisation endpoint."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.logging import get_logger
from app.core.security import verify_service_token
from app.schemas.routing import RouteRequest, RouteResponse
from app.services.routing_service import RoutingService, get_routing_service

router = APIRouter(prefix="/optimize", tags=["routing"], dependencies=[Depends(verify_service_token)])
logger = get_logger(__name__)


@router.post("/route", response_model=RouteResponse, summary="Route alternatives with cost inputs")
async def optimize_route(
    request: RouteRequest,
    service: RoutingService = Depends(get_routing_service),
) -> RouteResponse:
    """Return route alternatives with distance, duration and toll estimates.

    Fuel pricing and refuelling-stop selection are applied by the Laravel API,
    which holds the live price data; this endpoint supplies the geometry and
    the toll model.
    """
    response = await service.optimize(request)

    logger.info(
        "route_optimised",
        provider=response.provider,
        alternatives=len(response.routes),
        optimize_for=request.optimize_for,
    )

    return response
