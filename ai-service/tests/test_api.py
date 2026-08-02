"""End-to-end checks over the HTTP surface."""

from __future__ import annotations

from datetime import date, timedelta

import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


def test_health_reports_capabilities(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 200

    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["service"] == "fip-ai"
    # The admin console relies on these flags to show degraded capability.
    assert set(payload["capabilities"]) >= {"prophet", "trained_booster", "openai", "ocr"}


def test_metrics_endpoint_is_exposed(client: TestClient) -> None:
    response = client.get("/metrics")

    assert response.status_code == 200
    assert "fip_ai_requests_total" in response.text


def test_price_forecast_round_trip(client: TestClient) -> None:
    history = [
        {
            "date": (date.today() - timedelta(days=120 - offset)).isoformat(),
            "avg_price": round(60 + offset * 0.01, 4),
        }
        for offset in range(120)
    ]

    response = client.post(
        "/api/v1/predict/price",
        json={
            "fuel_type": "gasoline_ron95",
            "fuel_category": "gasoline",
            "forecast_for": (date.today() + timedelta(days=7)).isoformat(),
            "history": history,
            "advisories": [],
            "indicators": {
                "usd_php": [
                    {"date": (date.today() - timedelta(weeks=2)).isoformat(), "value": 57.2},
                    {"date": (date.today() - timedelta(weeks=1)).isoformat(), "value": 58.1},
                ]
            },
        },
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["direction"] in {"increase", "rollback", "no_change"}
    assert 0 <= payload["confidence"] <= 1
    assert payload["narrative"]


def test_invalid_forecast_payload_is_rejected(client: TestClient) -> None:
    response = client.post("/api/v1/predict/price", json={"fuel_type": "x"})

    assert response.status_code == 422


def test_ocr_rejects_a_non_image_upload(client: TestClient) -> None:
    response = client.post(
        "/api/v1/ocr/price-board",
        files={"file": ("notes.txt", b"not an image", "text/plain")},
    )

    assert response.status_code == 415


def test_assistant_answers_from_context_without_a_model(client: TestClient) -> None:
    response = client.post(
        "/api/v1/assistant/chat",
        json={
            "question": "Should I refuel today?",
            "context": {
                "forecasts": [
                    {
                        "fuel_type": "Gasoline RON 95",
                        "direction": "increase",
                        "change_amount": 0.55,
                        "confidence": 0.81,
                    }
                ]
            },
        },
    )

    assert response.status_code == 200

    payload = response.json()
    assert "refuel" in payload["answer"].lower()
    # Without an API key the answer must be labelled as ungrounded.
    assert payload["grounded"] is False
    assert payload["suggestions"]


def test_route_optimisation_returns_ranked_alternatives(client: TestClient) -> None:
    response = client.post(
        "/api/v1/optimize/route",
        json={
            "origin": {"lat": 14.5547, "lng": 121.0244},
            "destination": {"lat": 15.0342, "lng": 120.6839},
            "optimize_for": "cost",
            "alternatives": 3,
        },
    )

    assert response.status_code == 200

    payload = response.json()
    assert payload["routes"]

    for route in payload["routes"]:
        assert route["distance_km"] > 0
        assert route["duration_minutes"] > 0
