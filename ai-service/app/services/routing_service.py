"""Route alternatives with distance, duration and toll estimates.

Where a Google Directions key is configured the service proxies to it and
normalises the response. Without one it falls back to a geometric model tuned
for Philippine road conditions — great-circle distance inflated by a route
circuity factor, with speeds and toll costs derived from the corridor type.

The fallback exists because route *pricing* is what FIP actually adds; the
geometry is a commodity. A demo or a self-hosted deployment without a Maps key
should still be able to compare "cheapest versus fastest", and the response
labels the provider so the client can show a caveat.
"""

from __future__ import annotations

import math

import httpx

from app.core.config import get_settings
from app.core.logging import get_logger
from app.schemas.routing import Coordinate, RouteOption, RouteRequest, RouteResponse

logger = get_logger(__name__)

EARTH_RADIUS_KM = 6371.0

# Straight-line distance under-states road distance. Philippine urban road
# networks typically run 25–40% longer than the crow flies; inter-urban trips
# on expressways are closer to 15%.
URBAN_CIRCUITY = 1.35
HIGHWAY_CIRCUITY = 1.18

# Average achievable speeds, not speed limits.
URBAN_SPEED_KMH = 22.0        # Metro Manila reality, not aspiration
SUBURBAN_SPEED_KMH = 45.0
EXPRESSWAY_SPEED_KMH = 80.0

# NLEX/SLEX class 1 tolls run roughly ₱2.60/km for a private car.
TOLL_PHP_PER_KM = 2.60


class RoutingService:
    def __init__(self) -> None:
        self.settings = get_settings()

    async def optimize(self, request: RouteRequest) -> RouteResponse:
        google_key = self._google_key()

        if google_key:
            routes = await self._from_google(request, google_key)

            if routes:
                return RouteResponse(routes=routes, provider="google-directions")

            logger.warning("google_directions_empty_falling_back")

        return RouteResponse(routes=self._synthesise(request), provider="geometric-estimate")

    # -------------------------------------------------------------- Google

    async def _from_google(self, request: RouteRequest, api_key: str) -> list[RouteOption]:
        params = {
            "origin": f"{request.origin.lat},{request.origin.lng}",
            "destination": f"{request.destination.lat},{request.destination.lng}",
            "alternatives": "true",
            "departure_time": "now",       # enables live traffic durations
            "region": "ph",
            "key": api_key,
        }

        if request.avoid_tolls:
            params["avoid"] = "tolls"

        try:
            async with httpx.AsyncClient(timeout=15) as client:
                response = await client.get(
                    "https://maps.googleapis.com/maps/api/directions/json",
                    params=params,
                )
        except httpx.HTTPError as exc:
            logger.warning("google_directions_failed", error=str(exc))
            return []

        if response.status_code != 200:
            logger.warning("google_directions_status", status=response.status_code)
            return []

        payload = response.json()

        if payload.get("status") != "OK":
            logger.warning("google_directions_status_field", status=payload.get("status"))
            return []

        options: list[RouteOption] = []

        for route in payload.get("routes", [])[: request.alternatives]:
            leg = (route.get("legs") or [{}])[0]

            distance_km = float(leg.get("distance", {}).get("value", 0)) / 1000
            # duration_in_traffic is only present when departure_time is set.
            duration_seconds = float(
                leg.get("duration_in_traffic", {}).get("value")
                or leg.get("duration", {}).get("value", 0)
            )
            free_flow_seconds = float(leg.get("duration", {}).get("value", duration_seconds))

            has_tolls = any(
                "toll" in str(warning).lower() for warning in route.get("warnings", [])
            ) or bool(route.get("summary") and self._looks_like_expressway(route["summary"]))

            options.append(
                RouteOption(
                    summary=route.get("summary") or "Route",
                    polyline=route.get("overview_polyline", {}).get("points"),
                    distance_km=round(distance_km, 2),
                    duration_minutes=round(duration_seconds / 60, 1),
                    has_tolls=has_tolls,
                    toll_cost=round(distance_km * TOLL_PHP_PER_KM, 2) if has_tolls else 0.0,
                    traffic_level=self._traffic_level(duration_seconds, free_flow_seconds),
                    midpoint=self._midpoint_of(leg),
                )
            )

        return options

    @staticmethod
    def _looks_like_expressway(summary: str) -> bool:
        return any(
            token in summary.upper()
            for token in ("NLEX", "SLEX", "SCTEX", "TPLEX", "CAVITEX", "STAR", "SKYWAY", "NAIAX")
        )

    @staticmethod
    def _traffic_level(actual_seconds: float, free_flow_seconds: float) -> str:
        if free_flow_seconds <= 0:
            return "moderate"

        ratio = actual_seconds / free_flow_seconds

        if ratio > 1.5:
            return "heavy"
        if ratio > 1.2:
            return "moderate"

        return "light"

    @staticmethod
    def _midpoint_of(leg: dict) -> Coordinate | None:
        steps = leg.get("steps") or []

        if not steps:
            return None

        middle = steps[len(steps) // 2].get("end_location") or {}

        if "lat" not in middle or "lng" not in middle:
            return None

        return Coordinate(lat=float(middle["lat"]), lng=float(middle["lng"]))

    # ---------------------------------------------------- geometric fallback

    def _synthesise(self, request: RouteRequest) -> list[RouteOption]:
        """Build plausible alternatives from geometry alone."""
        straight_km = self._haversine(request.origin, request.destination)
        midpoint = self._midpoint(request.origin, request.destination)

        # Short trips are urban by definition; long ones will use expressways.
        is_long_haul = straight_km > 40

        options: list[RouteOption] = []

        # 1. Fastest: expressway where the distance justifies it.
        if is_long_haul and not request.avoid_tolls:
            distance = round(straight_km * HIGHWAY_CIRCUITY, 2)

            options.append(
                RouteOption(
                    summary="Expressway route",
                    distance_km=distance,
                    duration_minutes=round((distance / EXPRESSWAY_SPEED_KMH) * 60, 1),
                    has_tolls=True,
                    toll_cost=round(distance * TOLL_PHP_PER_KM, 2),
                    traffic_level="light",
                    midpoint=midpoint,
                )
            )

        # 2. Toll-free arterial route: longer and slower, but no toll.
        arterial_distance = round(straight_km * URBAN_CIRCUITY, 2)
        arterial_speed = SUBURBAN_SPEED_KMH if is_long_haul else URBAN_SPEED_KMH

        options.append(
            RouteOption(
                summary="Toll-free national roads",
                distance_km=arterial_distance,
                duration_minutes=round((arterial_distance / arterial_speed) * 60, 1),
                has_tolls=False,
                toll_cost=0.0,
                traffic_level="moderate" if is_long_haul else "heavy",
                midpoint=midpoint,
            )
        )

        # 3. A slightly longer alternative that avoids the worst congestion —
        #    the classic Waze detour.
        detour_distance = round(arterial_distance * 1.12, 2)

        options.append(
            RouteOption(
                summary="Alternate secondary roads",
                distance_km=detour_distance,
                duration_minutes=round((detour_distance / (arterial_speed * 1.25)) * 60, 1),
                has_tolls=False,
                toll_cost=0.0,
                traffic_level="light",
                midpoint=midpoint,
            )
        )

        return options[: request.alternatives]

    @staticmethod
    def _haversine(origin: Coordinate, destination: Coordinate) -> float:
        lat1, lng1 = math.radians(origin.lat), math.radians(origin.lng)
        lat2, lng2 = math.radians(destination.lat), math.radians(destination.lng)

        d_lat = lat2 - lat1
        d_lng = lng2 - lng1

        a = math.sin(d_lat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(d_lng / 2) ** 2

        return EARTH_RADIUS_KM * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))

    @staticmethod
    def _midpoint(origin: Coordinate, destination: Coordinate) -> Coordinate:
        return Coordinate(
            lat=(origin.lat + destination.lat) / 2,
            lng=(origin.lng + destination.lng) / 2,
        )

    def _google_key(self) -> str:
        import os

        return os.getenv("GOOGLE_MAPS_API_KEY", "")


_service: RoutingService | None = None


def get_routing_service() -> RoutingService:
    global _service

    if _service is None:
        _service = RoutingService()

    return _service
