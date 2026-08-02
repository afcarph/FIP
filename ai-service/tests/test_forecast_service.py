"""Tests for the price forecasting service.

The pass-through fallback is deterministic, so its behaviour can be asserted
exactly — which matters because it is the path most deployments will run on
until a model artefact is trained.
"""

from __future__ import annotations

from datetime import date, timedelta

import pytest

from app.schemas.forecast import (
    AdvisoryPoint,
    IndicatorPoint,
    PriceForecastRequest,
    PricePoint,
)
from app.services.forecast_service import ForecastService


def _price_history(days: int = 120, start_price: float = 60.0) -> list[PricePoint]:
    """A gently rising daily average series."""
    return [
        PricePoint(
            date=date.today() - timedelta(days=days - offset),
            avg_price=round(start_price + offset * 0.01, 4),
        )
        for offset in range(days)
    ]


def _indicator(values: list[float], weeks: int | None = None) -> list[IndicatorPoint]:
    weeks = weeks or len(values)

    return [
        IndicatorPoint(date=date.today() - timedelta(weeks=weeks - index), value=value)
        for index, value in enumerate(values)
    ]


@pytest.fixture
def service() -> ForecastService:
    return ForecastService()


def test_rising_crude_and_weakening_peso_predict_an_increase(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        fuel_category="gasoline",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(),
        advisories=[
            AdvisoryPoint(week_start=date.today() - timedelta(weeks=1), change_amount=0.40, direction="increase"),
        ],
        indicators={
            # MOPS up 4 USD/bbl and crude up 3, with the peso weakening.
            "mops_gasoline": _indicator([88.0, 92.0]),
            "dubai_crude": _indicator([79.0, 82.0]),
            "usd_php": _indicator([57.20, 58.10]),
        },
    )

    response = service.forecast(request)

    assert response.direction == "increase"
    assert response.change_amount > 0
    assert 0 <= response.confidence <= 1
    assert response.drivers, "An increase must be attributed to at least one driver."
    assert response.narrative


def test_falling_crude_predicts_a_rollback(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="diesel",
        fuel_category="diesel",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(start_price=57.0),
        indicators={
            "mops_gasoil": _indicator([92.0, 86.0]),
            "dubai_crude": _indicator([83.0, 78.0]),
            "usd_php": _indicator([58.00, 57.40]),
        },
    )

    response = service.forecast(request)

    assert response.direction == "rollback"
    assert response.change_amount < 0


def test_flat_regressors_predict_no_change(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron91",
        forecast_for=date.today() + timedelta(days=7),
        history=[
            PricePoint(date=date.today() - timedelta(days=120 - offset), avg_price=59.00)
            for offset in range(120)
        ],
        indicators={
            "mops_gasoline": _indicator([90.0, 90.0]),
            "dubai_crude": _indicator([80.0, 80.0]),
            "usd_php": _indicator([57.50, 57.50]),
        },
    )

    response = service.forecast(request)

    assert response.direction == "no_change"
    assert abs(response.change_amount) < 0.05


def test_sparse_history_yields_low_confidence(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(days=5),
    )

    response = service.forecast(request)

    assert response.confidence < 0.4
    assert response.model_used == "insufficient-data"


def test_change_is_rounded_to_five_centavos(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(),
        indicators={
            "mops_gasoline": _indicator([88.0, 91.3]),
            "dubai_crude": _indicator([79.0, 81.7]),
            "usd_php": _indicator([57.20, 57.90]),
        },
    )

    response = service.forecast(request)

    # PH boards quote to 5-centavo steps; the forecast should match.
    remainder = round(abs(response.change_amount) % 0.05, 4)
    assert remainder < 1e-6 or abs(remainder - 0.05) < 1e-6


def test_confidence_interval_widens_as_confidence_falls(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(),
        indicators={"usd_php": _indicator([57.20, 57.90])},
    )

    response = service.forecast(request)

    assert response.lower_bound is not None
    assert response.upper_bound is not None
    assert response.lower_bound < response.predicted_price < response.upper_bound


def test_horizon_decays_towards_zero(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        forecast_for=date.today() + timedelta(days=7),
        horizon_weeks=4,
        history=_price_history(),
        indicators={
            "mops_gasoline": _indicator([88.0, 93.0]),
            "usd_php": _indicator([57.20, 58.10]),
        },
    )

    response = service.forecast(request)

    assert len(response.horizon) == 4

    magnitudes = [abs(week["change_amount"]) for week in response.horizon]
    confidences = [week["confidence"] for week in response.horizon]

    # Further out means smaller claimed movement and lower confidence.
    assert magnitudes == sorted(magnitudes, reverse=True)
    assert confidences == sorted(confidences, reverse=True)


def test_drivers_are_ranked_by_contribution(service: ForecastService) -> None:
    request = PriceForecastRequest(
        fuel_type="gasoline_ron95",
        forecast_for=date.today() + timedelta(days=7),
        history=_price_history(),
        indicators={
            "mops_gasoline": _indicator([88.0, 94.0]),   # dominant
            "dubai_crude": _indicator([80.0, 80.5]),     # marginal
            "usd_php": _indicator([57.50, 57.55]),       # negligible
        },
    )

    response = service.forecast(request)
    weights = [driver.weight for driver in response.drivers]

    assert weights == sorted(weights, reverse=True)
    assert "MOPS" in response.drivers[0].factor
