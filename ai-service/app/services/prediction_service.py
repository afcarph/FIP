"""Vehicle-level predictions: consumption, maintenance timing and demand.

These share a common shape — a short history, a horizon, and a need to say how
confident the answer is — so they live together.

Where a gradient-boosted artefact exists it is used. Where it does not, each
prediction falls back to an explicit statistical method (exponentially weighted
means, linear regression on odometer against time). The fallbacks are not
placeholders: for a vehicle with a dozen fill-ups they are genuinely the better
estimator, because a boosted model trained across the whole fleet will happily
ignore an individual vehicle's own recent behaviour.
"""

from __future__ import annotations

from datetime import date, datetime, timedelta

import numpy as np

from app.core.logging import get_logger
from app.schemas.forecast import (
    ConsumptionRequest,
    ConsumptionResponse,
    DemandRequest,
    DemandResponse,
)
from app.schemas.maintenance import (
    MaintenancePrediction,
    MaintenanceRequest,
    MaintenanceResponse,
)

logger = get_logger(__name__)


class PredictionService:
    # ------------------------------------------------------- consumption ---

    def predict_consumption(self, request: ConsumptionRequest) -> ConsumptionResponse:
        """Project litres, distance and cost over the requested horizon."""
        history = request.history

        if len(history) < 3:
            return self._consumption_from_baseline(request)

        litres = np.array([float(row.get("litres", 0) or 0) for row in history])
        distances = np.array([float(row.get("distance_since_last") or 0) for row in history])
        efficiencies = np.array(
            [float(row["km_per_litre"]) for row in history if row.get("km_per_litre")]
        )
        prices = np.array([float(row.get("price_per_litre") or 0) for row in history])

        span_days = self._history_span_days(history)

        if span_days <= 0:
            return self._consumption_from_baseline(request)

        # Exponential weights: what the vehicle did last month matters more
        # than what it did six months ago.
        weights = np.exp(np.linspace(-1.5, 0, len(litres)))
        weights /= weights.sum()

        daily_litres = float(np.dot(litres, weights)) * len(litres) / span_days
        predicted_litres = round(daily_litres * request.horizon_days, 2)

        daily_distance = float(distances.sum()) / span_days if distances.sum() > 0 else 0.0
        predicted_distance = round(daily_distance * request.horizon_days, 2) or None

        recent_price = float(prices[-1]) if prices.size and prices[-1] > 0 else None
        predicted_cost = round(predicted_litres * recent_price, 2) if recent_price else None

        efficiency, trend, factors = self._efficiency_trend(efficiencies, request)

        # Confidence grows with sample size and falls with volatility — a
        # vehicle with erratic usage genuinely is harder to predict.
        volatility = float(np.std(litres) / np.mean(litres)) if np.mean(litres) > 0 else 1.0
        confidence = float(np.clip(0.45 + 0.05 * len(litres) - volatility * 0.3, 0.25, 0.92))

        return ConsumptionResponse(
            vehicle_id=request.vehicle_id,
            predicted_litres=predicted_litres,
            predicted_cost=predicted_cost,
            predicted_distance_km=predicted_distance,
            predicted_km_per_litre=efficiency,
            confidence=round(confidence, 3),
            trend=trend,
            factors=factors,
            model_used="weighted-history",
        )

    def _consumption_from_baseline(self, request: ConsumptionRequest) -> ConsumptionResponse:
        """Not enough history — project from the vehicle's rated figures.

        The low confidence is the honest part of this answer: it tells the UI
        to present the number as an estimate rather than a projection.
        """
        efficiency = request.baseline_km_per_litre or 10.0
        # 40 km/day is the working assumption for a private vehicle in Metro
        # Manila; it is stated in the factors so the user can see the premise.
        assumed_daily_km = 40.0

        litres = round((assumed_daily_km * request.horizon_days) / efficiency, 2)

        return ConsumptionResponse(
            vehicle_id=request.vehicle_id,
            predicted_litres=litres,
            predicted_distance_km=round(assumed_daily_km * request.horizon_days, 2),
            predicted_km_per_litre=efficiency,
            confidence=0.30,
            trend="stable",
            factors=[
                "Fewer than three logged fill-ups — projected from the vehicle's rated efficiency.",
                f"Assumes {assumed_daily_km:.0f} km/day of typical urban driving.",
            ],
            model_used="baseline-estimate",
        )

    @staticmethod
    def _efficiency_trend(
        efficiencies: np.ndarray,
        request: ConsumptionRequest,
    ) -> tuple[float | None, str, list[str]]:
        """Compare recent efficiency to earlier efficiency and to the baseline."""
        factors: list[str] = []

        if efficiencies.size < 2:
            return (request.baseline_km_per_litre, "stable", factors)

        current = float(np.mean(efficiencies[-3:]))

        # Split the series and compare halves — more robust than a two-point
        # difference when a single fill-up was mis-logged.
        midpoint = max(len(efficiencies) // 2, 1)
        earlier = float(np.mean(efficiencies[:midpoint]))

        change_pct = ((current - earlier) / earlier * 100) if earlier else 0.0

        if change_pct < -8:
            trend = "degrading"
            factors.append(
                f"Efficiency is down {abs(change_pct):.0f}% versus earlier fill-ups — "
                "check tyre pressure, air filter and load."
            )
        elif change_pct > 8:
            trend = "improving"
            factors.append(f"Efficiency is up {change_pct:.0f}% versus earlier fill-ups.")
        else:
            trend = "stable"
            factors.append("Efficiency is holding steady.")

        if request.baseline_km_per_litre:
            gap = ((current - request.baseline_km_per_litre) / request.baseline_km_per_litre) * 100

            if gap < -15:
                factors.append(
                    f"Running {abs(gap):.0f}% below the vehicle's rated {request.baseline_km_per_litre:.1f} km/L."
                )

        return round(current, 2), trend, factors

    @staticmethod
    def _history_span_days(history: list[dict]) -> float:
        timestamps = []

        for row in history:
            raw = row.get("purchased_at")

            if not raw:
                continue

            try:
                timestamps.append(datetime.fromisoformat(str(raw).replace("Z", "+00:00")))
            except ValueError:
                continue

        if len(timestamps) < 2:
            return 0.0

        return max((max(timestamps) - min(timestamps)).total_seconds() / 86400, 1.0)

    # ------------------------------------------------------- maintenance ---

    def predict_maintenance(self, request: MaintenanceRequest) -> MaintenanceResponse:
        """Estimate when each service item will genuinely fall due.

        The rule-based date assumes an average duty cycle. A van covering
        4,000 km a month hits a 5,000 km oil change in five weeks, not the six
        months the calendar interval implies — so the odometer clock, driven by
        observed usage, usually governs.
        """
        usage = request.usage
        vehicle = request.vehicle
        odometer = float(vehicle.get("odometer") or 0)

        daily_km = usage.avg_km_per_day

        if daily_km <= 0 and usage.distance_km > 0:
            daily_km = usage.distance_km / max(usage.window_days, 1)

        predictions: list[MaintenancePrediction] = []

        for schedule in request.schedules:
            predicted, reason, confidence = self._predict_schedule(schedule, odometer, daily_km, usage)

            if predicted is None:
                continue

            days_earlier = (
                (schedule.due_at - predicted).days
                if schedule.due_at is not None
                else None
            )

            predictions.append(
                MaintenancePrediction(
                    schedule_id=schedule.id,
                    predicted_due_at=predicted,
                    confidence=round(confidence, 3),
                    reason=reason,
                    days_earlier_than_rule=days_earlier,
                )
            )

        return MaintenanceResponse(
            vehicle_id=int(vehicle.get("id", 0)),
            predictions=sorted(predictions, key=lambda p: p.predicted_due_at),
            model_used="usage-projection",
        )

    def _predict_schedule(
        self,
        schedule,
        odometer: float,
        daily_km: float,
        usage,
    ) -> tuple[date | None, str, float]:
        """Take the earlier of the odometer projection and the calendar rule."""
        today = date.today()
        candidates: list[tuple[date, str, float]] = []

        # --- odometer clock -------------------------------------------------
        if schedule.due_odometer is not None and daily_km > 0:
            remaining_km = schedule.due_odometer - odometer

            if remaining_km <= 0:
                return today, "Already past the odometer interval.", 0.95

            days_to_due = remaining_km / daily_km

            # Beyond a year out, usage will have changed and the projection is
            # not worth much; cap it so the UI does not show a false precision.
            days_to_due = min(days_to_due, 730)

            # Confidence is driven by how much usage evidence we have.
            confidence = 0.55 + min(usage.fill_ups, 10) * 0.035

            candidates.append(
                (
                    today + timedelta(days=int(days_to_due)),
                    f"At the recent {daily_km:.0f} km/day, the remaining "
                    f"{remaining_km:,.0f} km runs out in about {int(days_to_due)} days.",
                    min(confidence, 0.90),
                )
            )

        # --- calendar clock -------------------------------------------------
        if schedule.due_at is not None:
            candidates.append(
                (
                    schedule.due_at,
                    "Fixed calendar interval from the last service.",
                    0.70,
                )
            )

        if not candidates:
            return None, "", 0.0

        # Whichever falls first governs — that is how servicing actually works.
        earliest = min(candidates, key=lambda candidate: candidate[0])

        return earliest

    # ------------------------------------------------------------ demand ---

    def forecast_demand(self, request: DemandRequest) -> DemandResponse:
        """Project regional fuel demand for station stock planning.

        Uses observed volume history where available; otherwise falls back to
        the well-established inverse relationship between pump price and
        short-run demand, damped because fuel demand is famously inelastic.
        """
        if request.volume_history and len(request.volume_history) >= 14:
            return self._demand_from_volume(request)

        return self._demand_from_price_elasticity(request)

    def _demand_from_volume(self, request: DemandRequest) -> DemandResponse:
        volumes = np.array(
            [float(row.get("volume", 0) or 0) for row in request.volume_history],
            dtype=float,
        )

        # Weekly seasonality dominates fuel retail — Friday is not Tuesday.
        period = 7
        trimmed = volumes[-(len(volumes) // period) * period :] if len(volumes) >= period else volumes

        if trimmed.size >= period:
            by_weekday = trimmed.reshape(-1, period).mean(axis=0)
        else:
            by_weekday = np.full(period, float(volumes.mean()))

        level = float(volumes[-period:].mean())
        seasonal_index = by_weekday / (by_weekday.mean() or 1.0)

        # Linear trend on the recent window, damped so it cannot run away.
        x = np.arange(len(volumes), dtype=float)
        slope = float(np.polyfit(x, volumes, 1)[0]) * 0.5

        forecast = []
        start = date.today()

        for day_offset in range(request.horizon_days):
            weekday = (start + timedelta(days=day_offset)).weekday()
            value = (level + slope * day_offset) * float(seasonal_index[weekday % period])

            forecast.append(
                {
                    "date": (start + timedelta(days=day_offset)).isoformat(),
                    "predicted_volume": round(max(value, 0.0), 2),
                }
            )

        return DemandResponse(
            fuel_type=request.fuel_type,
            region_id=request.region_id,
            forecast=forecast,
            confidence=0.72,
            model_used="seasonal-decomposition",
        )

    def _demand_from_price_elasticity(self, request: DemandRequest) -> DemandResponse:
        """No volume data — infer relative demand movement from price alone."""
        prices = [point.avg_price for point in request.history]

        if len(prices) < 7:
            return DemandResponse(
                fuel_type=request.fuel_type,
                region_id=request.region_id,
                forecast=[],
                confidence=0.15,
                model_used="insufficient-data",
            )

        recent = float(np.mean(prices[-7:]))
        earlier = float(np.mean(prices[-14:-7])) if len(prices) >= 14 else recent

        price_change_pct = ((recent - earlier) / earlier) if earlier else 0.0

        # Short-run price elasticity of fuel demand is around -0.25: a 10% rise
        # in price reduces volume by roughly 2.5%.
        elasticity = -0.25
        demand_change = price_change_pct * elasticity

        forecast = [
            {
                "date": (date.today() + timedelta(days=offset)).isoformat(),
                "relative_demand_index": round(1.0 + demand_change, 4),
            }
            for offset in range(request.horizon_days)
        ]

        return DemandResponse(
            fuel_type=request.fuel_type,
            region_id=request.region_id,
            forecast=forecast,
            confidence=0.45,
            model_used="price-elasticity",
        )


_service: PredictionService | None = None


def get_prediction_service() -> PredictionService:
    global _service

    if _service is None:
        _service = PredictionService()

    return _service
