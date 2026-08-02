"""Weekly pump-price forecasting.

Two models run in sequence and their outputs are blended:

**Prophet** captures the level and the seasonal shape of the national average
price series. It is good at trend, indifferent to the exogenous shocks that
actually drive Philippine weekly adjustments.

**Gradient boosting** models the *change* directly from the regressors that
matter here — regional refined-product cracks (MOPS), Dubai crude and the
USD/PHP rate — because a DOE adjustment is essentially a pass-through of
last week's import cost.

When neither artefact is available (a cold start, or the training job has not
run) the service falls back to a transparent weighted linear model. That
fallback is deliberately simple and its weights are published in the response,
so a user reading "why" always sees the real reasoning rather than a black box.

The pass-through relationship the fallback encodes:

    Δpump ≈ w_mops · Δmops_php + w_crude · Δcrude_php + w_fx · fx_effect
            + w_momentum · recent_trend

with every term first converted to pesos per litre.
"""

from __future__ import annotations

import warnings
from datetime import date, timedelta
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd

from app.core.config import get_settings
from app.core.logging import get_logger
from app.schemas.forecast import (
    ForecastDriver,
    PriceForecastRequest,
    PriceForecastResponse,
)

logger = get_logger(__name__)
warnings.filterwarnings("ignore", category=FutureWarning)

# One barrel is 158.987 litres. Crude quoted in USD/bbl converts to PHP/L as
#   price_usd_per_bbl / 158.987 * usd_php
LITRES_PER_BARREL = 158.987


class ForecastService:
    """Produces the weekly price forecast and its explanation."""

    def __init__(self) -> None:
        self.settings = get_settings()
        self._prophet_available = self._probe_prophet()
        self._booster: Any | None = None
        self._load_booster()

    # ------------------------------------------------------------------ API

    def forecast(self, request: PriceForecastRequest) -> PriceForecastResponse:
        """Predict the coming week's adjustment for one fuel type."""
        history = self._history_frame(request)
        regressors = self._regressor_deltas(request)

        change, confidence, model_used = self._predict_change(request, history, regressors)

        # PH pump prices move in 5-centavo steps; rounding keeps the published
        # figure consistent with what the boards actually show.
        step = self.settings.price_step
        change = round(round(change / step) * step, 4)

        latest_price = float(history["y"].iloc[-1]) if not history.empty else None
        predicted_price = round(latest_price + change, 4) if latest_price is not None else None

        # The interval widens as confidence falls — an honest forecast says how
        # unsure it is rather than quoting a false point estimate.
        spread = round(max(0.10, (1 - confidence) * 1.2), 4)

        drivers = self._build_drivers(regressors, history)

        return PriceForecastResponse(
            fuel_type=request.fuel_type,
            forecast_for=request.forecast_for,
            direction=self._direction(change),
            change_amount=change,
            predicted_price=predicted_price,
            lower_bound=round(predicted_price - spread, 4) if predicted_price else None,
            upper_bound=round(predicted_price + spread, 4) if predicted_price else None,
            confidence=round(confidence, 3),
            drivers=drivers,
            narrative=self._narrative(request, change, confidence, drivers),
            model_used=model_used,
            horizon=self._multi_week_horizon(request, change, confidence),
        )

    # ------------------------------------------------------- model dispatch

    def _predict_change(
        self,
        request: PriceForecastRequest,
        history: pd.DataFrame,
        regressors: dict[str, float],
    ) -> tuple[float, float, str]:
        """Return (change in PHP/L, confidence 0–1, model identifier)."""
        # Not enough history to model anything: say so rather than guess.
        if len(history) < 14:
            logger.warning("insufficient_history", points=len(history), fuel=request.fuel_type)
            return 0.0, 0.25, "insufficient-data"

        estimates: list[tuple[float, float, str]] = []

        booster_estimate = self._predict_with_booster(regressors)
        if booster_estimate is not None:
            estimates.append((*booster_estimate, "xgboost"))

        prophet_estimate = self._predict_with_prophet(history)
        if prophet_estimate is not None:
            estimates.append((*prophet_estimate, "prophet"))

        linear_change, linear_confidence = self._predict_with_pass_through(regressors, history)
        estimates.append((linear_change, linear_confidence, "pass-through"))

        if len(estimates) == 1:
            change, confidence, name = estimates[0]
            return change, confidence, name

        # Confidence-weighted blend: a model that is sure pulls the ensemble
        # towards itself, and agreement between models raises the result.
        weights = np.array([conf for _, conf, _ in estimates], dtype=float)
        values = np.array([change for change, _, _ in estimates], dtype=float)
        weights = weights / weights.sum()

        blended = float(np.dot(values, weights))

        # Disagreement between models is itself evidence of uncertainty.
        dispersion = float(np.std(values))
        agreement_penalty = min(dispersion / 1.5, 0.35)
        confidence = float(np.dot(weights, [c for _, c, _ in estimates])) - agreement_penalty

        return blended, float(np.clip(confidence, 0.15, 0.95)), "+".join(n for _, _, n in estimates)

    def _predict_with_booster(self, regressors: dict[str, float]) -> tuple[float, float] | None:
        """Gradient-boosted prediction, when a trained artefact is present."""
        if self._booster is None:
            return None

        try:
            features = np.array(
                [[
                    regressors.get("mops_delta_php", 0.0),
                    regressors.get("crude_delta_php", 0.0),
                    regressors.get("fx_delta_pct", 0.0),
                    regressors.get("momentum", 0.0),
                    regressors.get("weeks_since_change", 0.0),
                ]],
                dtype=float,
            )

            prediction = float(self._booster.predict(features)[0])
        except Exception as exc:  # noqa: BLE001 — never let a model kill the request
            logger.warning("booster_prediction_failed", error=str(exc))
            return None

        return prediction, 0.85

    def _predict_with_prophet(self, history: pd.DataFrame) -> tuple[float, float] | None:
        """Prophet on the level series; the change is the implied step."""
        if not self._prophet_available or len(history) < 60:
            return None

        try:
            from prophet import Prophet

            model = Prophet(
                weekly_seasonality=True,
                yearly_seasonality=True,
                daily_seasonality=False,
                changepoint_prior_scale=0.08,
                interval_width=0.80,
            )
            model.fit(history[["ds", "y"]])

            future = model.make_future_dataframe(periods=7)
            forecast = model.predict(future)

            current = float(history["y"].iloc[-1])
            projected = float(forecast["yhat"].iloc[-1])
            lower = float(forecast["yhat_lower"].iloc[-1])
            upper = float(forecast["yhat_upper"].iloc[-1])

            # A narrow interval means Prophet is confident in its own trend.
            interval_width = max(upper - lower, 0.01)
            confidence = float(np.clip(1.0 - (interval_width / 4.0), 0.3, 0.9))

            return projected - current, confidence
        except Exception as exc:  # noqa: BLE001
            logger.warning("prophet_failed", error=str(exc))
            return None

    def _predict_with_pass_through(
        self,
        regressors: dict[str, float],
        history: pd.DataFrame,
    ) -> tuple[float, float]:
        """Transparent weighted model — always available, always explainable."""
        weights = self.settings.fallback_weights

        change = (
            weights["mops"] * regressors.get("mops_delta_php", 0.0)
            + weights["crude"] * regressors.get("crude_delta_php", 0.0)
            + weights["fx"] * regressors.get("fx_effect_php", 0.0)
            + weights["momentum"] * regressors.get("momentum", 0.0)
        )

        # Confidence rises with how much of the regressor set we actually have.
        available = sum(
            1
            for key in ("mops_delta_php", "crude_delta_php", "fx_effect_php")
            if abs(regressors.get(key, 0.0)) > 1e-9
        )
        confidence = 0.35 + 0.15 * available

        # A long, stable history makes the momentum term more trustworthy.
        if len(history) > 180:
            confidence += 0.05

        return change, float(np.clip(confidence, 0.25, 0.80))

    # --------------------------------------------------------- feature prep

    def _history_frame(self, request: PriceForecastRequest) -> pd.DataFrame:
        """Prophet-shaped frame: `ds` (date) and `y` (national average price)."""
        if not request.history:
            return pd.DataFrame(columns=["ds", "y"])

        frame = pd.DataFrame(
            [{"ds": point.date, "y": point.avg_price} for point in request.history]
        )
        frame["ds"] = pd.to_datetime(frame["ds"])

        return frame.sort_values("ds").drop_duplicates("ds").reset_index(drop=True)

    def _regressor_deltas(self, request: PriceForecastRequest) -> dict[str, float]:
        """Convert raw indicator series into peso-per-litre weekly deltas.

        Everything is expressed in the same unit as the answer so the driver
        panel can show real numbers rather than opaque coefficients.
        """
        deltas: dict[str, float] = {}

        fx_now, fx_prev = self._last_two(request.indicators.get("usd_php"))
        fx_rate = fx_now if fx_now is not None else 57.0

        if fx_now is not None and fx_prev:
            deltas["fx_delta_pct"] = (fx_now - fx_prev) / fx_prev
            # A weaker peso raises the peso cost of every imported litre. Using
            # a ₱58/L pump price as the import-content base gives the effect a
            # sensible magnitude without needing the full cost stack.
            deltas["fx_effect_php"] = deltas["fx_delta_pct"] * 58.0 * 0.55

        # Regional refined product is the dominant input for PH pump prices.
        mops_key = "mops_gasoil" if request.fuel_category == "diesel" else "mops_gasoline"
        mops_now, mops_prev = self._last_two(request.indicators.get(mops_key))

        if mops_now is not None and mops_prev is not None:
            deltas["mops_delta_php"] = ((mops_now - mops_prev) / LITRES_PER_BARREL) * fx_rate

        crude_now, crude_prev = self._last_two(
            request.indicators.get("dubai_crude") or request.indicators.get("brent")
        )

        if crude_now is not None and crude_prev is not None:
            deltas["crude_delta_php"] = ((crude_now - crude_prev) / LITRES_PER_BARREL) * fx_rate

        # Recent adjustment momentum: the mean of the last three weeks, damped.
        recent = [advisory.change_amount for advisory in request.advisories[:3]]
        deltas["momentum"] = float(np.mean(recent)) * 0.5 if recent else 0.0

        # How long since the last non-zero move — long flat runs often precede
        # a catch-up adjustment.
        weeks_flat = 0
        for advisory in request.advisories:
            if abs(advisory.change_amount) < 0.01:
                weeks_flat += 1
            else:
                break
        deltas["weeks_since_change"] = float(weeks_flat)

        return deltas

    @staticmethod
    def _last_two(series: list | None) -> tuple[float | None, float | None]:
        """Most recent and preceding value from an indicator series."""
        if not series or len(series) < 2:
            if series and len(series) == 1:
                value = series[0].value if hasattr(series[0], "value") else series[0]["value"]
                return float(value), None
            return None, None

        ordered = sorted(
            series,
            key=lambda point: point.date if hasattr(point, "date") else point["date"],
        )

        def value_of(point: Any) -> float:
            return float(point.value if hasattr(point, "value") else point["value"])

        return value_of(ordered[-1]), value_of(ordered[-2])

    # ----------------------------------------------------------- explaining

    def _build_drivers(
        self,
        regressors: dict[str, float],
        history: pd.DataFrame,
    ) -> list[ForecastDriver]:
        """Rank the contributors by their absolute peso contribution."""
        weights = self.settings.fallback_weights

        candidates = [
            ("Regional product crack (MOPS)", "mops", regressors.get("mops_delta_php", 0.0), "₱{:+.2f}/L"),
            ("Dubai crude", "crude", regressors.get("crude_delta_php", 0.0), "₱{:+.2f}/L"),
            ("USD/PHP exchange rate", "fx", regressors.get("fx_effect_php", 0.0), "₱{:+.2f}/L"),
            ("Recent adjustment momentum", "momentum", regressors.get("momentum", 0.0), "₱{:+.2f}/L"),
        ]

        contributions = [
            (label, abs(weights[key] * value), value, fmt)
            for label, key, value, fmt in candidates
        ]

        total = sum(magnitude for _, magnitude, _, _ in contributions) or 1.0

        drivers = [
            ForecastDriver(
                factor=label,
                weight=round(magnitude / total, 3),
                value=fmt.format(value),
                direction="up" if value > 0.001 else "down" if value < -0.001 else "flat",
            )
            for label, magnitude, value, fmt in contributions
            if magnitude > 1e-6
        ]

        if not history.empty and len(history) >= 30:
            recent_trend = float(history["y"].iloc[-1] - history["y"].iloc[-30])
            drivers.append(
                ForecastDriver(
                    factor="30-day price trend",
                    weight=0.05,
                    value=f"₱{recent_trend:+.2f}/L",
                    direction="up" if recent_trend > 0 else "down" if recent_trend < 0 else "flat",
                )
            )

        return sorted(drivers, key=lambda d: d.weight, reverse=True)

    def _narrative(
        self,
        request: PriceForecastRequest,
        change: float,
        confidence: float,
        drivers: list[ForecastDriver],
    ) -> str:
        """Plain-language summary, phrased for a motorist rather than a trader."""
        if not drivers:
            return (
                "There is not enough market data this week to call the adjustment "
                "with any confidence."
            )

        lead = drivers[0]
        effective = request.forecast_for + timedelta(days=1)  # DOE adjusts Tuesday
        confidence_word = (
            "high" if confidence >= 0.8 else "moderate" if confidence >= 0.6 else "low"
        )

        if abs(change) < 0.05:
            return (
                f"{lead.factor} is broadly flat, so {request.fuel_type} prices are expected "
                f"to hold on {effective:%d %B} ({confidence_word} confidence)."
            )

        movement = "increase" if change > 0 else "rollback"
        supporting = ", ".join(driver.factor.lower() for driver in drivers[1:3])

        sentence = (
            f"{lead.factor} moved {lead.value} week on week, pointing to a "
            f"₱{abs(change):.2f}/L {movement} on {effective:%d %B} "
            f"({confidence_word} confidence)."
        )

        if supporting:
            sentence += f" Also contributing: {supporting}."

        return sentence

    def _multi_week_horizon(
        self,
        request: PriceForecastRequest,
        first_week_change: float,
        confidence: float,
    ) -> list[dict]:
        """Project further weeks with decaying magnitude and confidence.

        Weekly adjustments mean-revert: a large move is usually followed by a
        smaller one in the same direction, then a correction. Decaying rather
        than repeating the first-week estimate avoids compounding an error.
        """
        horizon: list[dict] = []
        change = first_week_change
        current_confidence = confidence

        for week in range(1, request.horizon_weeks + 1):
            horizon.append(
                {
                    "week_start": (request.forecast_for + timedelta(weeks=week - 1)).isoformat(),
                    "change_amount": round(change, 4),
                    "direction": self._direction(change),
                    "confidence": round(current_confidence, 3),
                }
            )

            change *= 0.55
            current_confidence *= 0.75

        return horizon

    @staticmethod
    def _direction(change: float) -> str:
        if change > 0.001:
            return "increase"
        if change < -0.001:
            return "rollback"
        return "no_change"

    # ---------------------------------------------------------- artefact IO

    def _load_booster(self) -> None:
        """Load the trained gradient-boosted model if the artefact exists."""
        path = Path(self.settings.model_dir) / f"price_forecast_{self.settings.forecast_model_version}.joblib"

        if not path.exists():
            logger.info("booster_artefact_absent", path=str(path))
            return

        try:
            import joblib

            self._booster = joblib.load(path)
            logger.info("booster_loaded", path=str(path))
        except Exception as exc:  # noqa: BLE001
            logger.warning("booster_load_failed", path=str(path), error=str(exc))
            self._booster = None

    @staticmethod
    def _probe_prophet() -> bool:
        try:
            import prophet  # noqa: F401

            return True
        except ImportError:
            logger.info("prophet_unavailable")
            return False


_service: ForecastService | None = None


def get_forecast_service() -> ForecastService:
    """Process-wide singleton — loading model artefacts is expensive."""
    global _service

    if _service is None:
        _service = ForecastService()

    return _service
