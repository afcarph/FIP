"""Tests for unsupervised anomaly detection."""

from __future__ import annotations

from datetime import datetime, timedelta

import pytest

from app.schemas.fraud import FraudRequest, Transaction
from app.services.fraud_service import FraudService


def _normal_transaction(index: int) -> Transaction:
    """A well-behaved fill-up for a 60 L van returning ~10 km/L."""
    return Transaction(
        id=index,
        vehicle_id=1,
        driver_id=1,
        litres=45.0,
        price_per_litre=58.0,
        total_cost=2610.0,
        odometer=50_000 + index * 450,
        distance_since_last=450.0,
        km_per_litre=10.0,
        tank_capacity=60.0,
        baseline_km_per_litre=10.0,
        purchased_at=(datetime(2026, 1, 1) + timedelta(days=index * 7)).isoformat(),
    )


@pytest.fixture
def service() -> FraudService:
    return FraudService()


def test_a_small_sample_is_not_scored(service: FraudService) -> None:
    request = FraudRequest(transactions=[_normal_transaction(i) for i in range(1, 6)])

    response = service.detect(request)

    # Five rows cannot define a population; scoring them would be noise.
    assert response.results == []
    assert response.model_used == "insufficient-sample"


def test_a_clean_population_produces_few_or_no_alerts(service: FraudService) -> None:
    request = FraudRequest(transactions=[_normal_transaction(i) for i in range(1, 41)])

    response = service.detect(request)

    assert response.samples_scored == 40
    # Uniform data has no genuine outliers to find.
    assert len(response.results) <= 2


def test_a_gross_overfill_is_isolated(service: FraudService) -> None:
    transactions = [_normal_transaction(i) for i in range(1, 41)]

    # 110 L into a 60 L tank, with an efficiency figure to match the excess.
    transactions.append(
        Transaction(
            id=999,
            vehicle_id=1,
            driver_id=1,
            litres=110.0,
            price_per_litre=58.0,
            total_cost=6380.0,
            odometer=70_000,
            distance_since_last=450.0,
            km_per_litre=4.1,
            tank_capacity=60.0,
            baseline_km_per_litre=10.0,
            purchased_at=datetime(2026, 9, 1).isoformat(),
        )
    )

    response = service.detect(FraudRequest(transactions=transactions))
    flagged = {result.id for result in response.results}

    assert 999 in flagged, "A 110 L fill into a 60 L tank must be isolated."

    outlier = next(result for result in response.results if result.id == 999)
    assert outlier.anomaly_score >= 0.5
    assert outlier.contributing_features


def test_results_are_ordered_by_score(service: FraudService) -> None:
    transactions = [_normal_transaction(i) for i in range(1, 41)]

    transactions.append(
        Transaction(
            id=901, vehicle_id=1, litres=115.0, price_per_litre=58.0, total_cost=6670.0,
            odometer=71_000, distance_since_last=400.0, km_per_litre=3.5,
            tank_capacity=60.0, baseline_km_per_litre=10.0,
            purchased_at=datetime(2026, 9, 1).isoformat(),
        )
    )
    transactions.append(
        Transaction(
            id=902, vehicle_id=1, litres=52.0, price_per_litre=58.0, total_cost=3016.0,
            odometer=71_500, distance_since_last=500.0, km_per_litre=9.6,
            tank_capacity=60.0, baseline_km_per_litre=10.0,
            purchased_at=datetime(2026, 9, 8).isoformat(),
        )
    )

    response = service.detect(FraudRequest(transactions=transactions))
    scores = [result.anomaly_score for result in response.results]

    assert scores == sorted(scores, reverse=True)


def test_scoring_is_reproducible(service: FraudService) -> None:
    transactions = [_normal_transaction(i) for i in range(1, 41)]
    transactions.append(
        Transaction(
            id=999, vehicle_id=1, litres=120.0, price_per_litre=58.0, total_cost=6960.0,
            odometer=72_000, distance_since_last=300.0, km_per_litre=2.5,
            tank_capacity=60.0, baseline_km_per_litre=10.0,
            purchased_at=datetime(2026, 9, 1).isoformat(),
        )
    )

    first = service.detect(FraudRequest(transactions=transactions))
    second = service.detect(FraudRequest(transactions=transactions))

    # A fixed random_state means an investigation can be reproduced later.
    assert [(r.id, r.anomaly_score) for r in first.results] == [
        (r.id, r.anomaly_score) for r in second.results
    ]
