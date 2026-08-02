"""Unsupervised anomaly detection over fuel transactions.

Laravel already runs six deterministic rules inline on every fill-up. This
service exists for what rules cannot see: the driver whose every individual
transaction looks fine but whose *pattern* is wrong — slightly over-dispensing
every week, or consistently claiming distances the fuel does not support.

An isolation forest suits that job. It needs no labelled fraud (which nobody
has), it scales to a fleet's whole history, and its per-feature deviations can
be reported back so a manager sees why a row was flagged rather than a bare
score.

The features are all *relative*: litres as a fraction of tank capacity,
efficiency as a ratio to the vehicle's own baseline. Absolute values would
simply rank trucks above motorcycles.
"""

from __future__ import annotations

from datetime import datetime

import numpy as np
import pandas as pd
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler

from app.core.logging import get_logger
from app.schemas.fraud import FraudRequest, FraudResponse, FraudResult, Transaction

logger = get_logger(__name__)

FEATURE_NAMES = [
    "tank_fill_ratio",
    "efficiency_ratio",
    "cost_per_km",
    "hours_since_previous",
    "price_deviation",
    "distance_per_day",
]


class FraudService:
    def detect(self, request: FraudRequest) -> FraudResponse:
        frame = self._feature_frame(request.transactions)

        # An isolation forest needs a population to isolate against. Below ~20
        # rows the "anomalies" are just the tails of a tiny sample, so we defer
        # to the rule tier rather than emitting noise.
        if len(frame) < 20:
            logger.info("fraud_sample_too_small", size=len(frame))

            return FraudResponse(results=[], model_used="insufficient-sample", samples_scored=len(frame))

        features = frame[FEATURE_NAMES].to_numpy(dtype=float)
        scaled = StandardScaler().fit_transform(features)

        forest = IsolationForest(
            n_estimators=200,
            contamination=request.contamination,
            random_state=42,       # reproducible: the same data must score the same
            n_jobs=-1,
        )
        forest.fit(scaled)

        # decision_function is positive for inliers; invert and normalise so a
        # higher number always means "more anomalous", matching the rule tier.
        raw_scores = -forest.decision_function(scaled)
        normalised = self._normalise(raw_scores)

        results: list[FraudResult] = []

        for position, (_, row) in enumerate(frame.iterrows()):
            score = float(normalised[position])

            if score < 0.5:
                continue    # Laravel applies the final threshold; below 0.5 is noise

            deviations = self._feature_deviations(scaled[position])

            results.append(
                FraudResult(
                    id=int(row["id"]),
                    anomaly_score=round(score, 3),
                    alert_type=self._classify(deviations, row),
                    contributing_features=deviations,
                )
            )

        results.sort(key=lambda result: result.anomaly_score, reverse=True)

        return FraudResponse(
            results=results,
            model_used="isolation_forest",
            samples_scored=len(frame),
        )

    # -------------------------------------------------------- feature build

    def _feature_frame(self, transactions: list[Transaction]) -> pd.DataFrame:
        rows: list[dict] = []
        previous_by_vehicle: dict[int | None, datetime] = {}

        # Chronological order so "time since previous fill" is meaningful.
        ordered = sorted(transactions, key=lambda t: t.purchased_at)

        median_price = float(np.median([t.price_per_litre for t in ordered])) or 1.0

        for transaction in ordered:
            purchased_at = self._parse_timestamp(transaction.purchased_at)

            previous = previous_by_vehicle.get(transaction.vehicle_id)
            hours_since = (
                (purchased_at - previous).total_seconds() / 3600
                if previous is not None and purchased_at is not None
                else 168.0     # a week: a neutral value for a first observation
            )

            if purchased_at is not None:
                previous_by_vehicle[transaction.vehicle_id] = purchased_at

            # Litres relative to tank size — comparable across vehicle classes.
            tank_fill_ratio = (
                transaction.litres / transaction.tank_capacity
                if transaction.tank_capacity
                else 0.75
            )

            # Efficiency relative to this vehicle's own baseline. 1.0 is normal;
            # much above means distance without fuel, much below means the
            # opposite.
            efficiency_ratio = (
                transaction.km_per_litre / transaction.baseline_km_per_litre
                if transaction.km_per_litre and transaction.baseline_km_per_litre
                else 1.0
            )

            cost_per_km = (
                transaction.total_cost / transaction.distance_since_last
                if transaction.distance_since_last
                else 0.0
            )

            distance_per_day = (
                transaction.distance_since_last / max(hours_since / 24, 0.1)
                if transaction.distance_since_last
                else 0.0
            )

            rows.append(
                {
                    "id": transaction.id,
                    "vehicle_id": transaction.vehicle_id,
                    "driver_id": transaction.driver_id,
                    "tank_fill_ratio": float(np.clip(tank_fill_ratio, 0, 3)),
                    "efficiency_ratio": float(np.clip(efficiency_ratio, 0, 5)),
                    "cost_per_km": float(np.clip(cost_per_km, 0, 100)),
                    "hours_since_previous": float(np.clip(hours_since, 0, 2160)),
                    "price_deviation": float(transaction.price_per_litre / median_price),
                    "distance_per_day": float(np.clip(distance_per_day, 0, 2000)),
                }
            )

        return pd.DataFrame(rows)

    @staticmethod
    def _parse_timestamp(value: str) -> datetime | None:
        try:
            return datetime.fromisoformat(value.replace("Z", "+00:00"))
        except (ValueError, AttributeError):
            return None

    # ------------------------------------------------------- interpretation

    @staticmethod
    def _normalise(scores: np.ndarray) -> np.ndarray:
        """Map raw scores onto 0–1 without letting one outlier flatten the rest."""
        low = float(np.percentile(scores, 5))
        high = float(np.percentile(scores, 99))

        if high - low < 1e-9:
            return np.zeros_like(scores)

        return np.clip((scores - low) / (high - low), 0.0, 1.0)

    @staticmethod
    def _feature_deviations(scaled_row: np.ndarray) -> list[dict]:
        """Which features pushed this row away from the population, and how far."""
        deviations = [
            {
                "feature": name,
                "z_score": round(float(value), 2),
                "direction": "high" if value > 0 else "low",
            }
            for name, value in zip(FEATURE_NAMES, scaled_row, strict=False)
            if abs(value) > 1.5
        ]

        return sorted(deviations, key=lambda item: abs(item["z_score"]), reverse=True)[:4]

    @staticmethod
    def _classify(deviations: list[dict], row: pd.Series) -> str:
        """Give the anomaly a name a fleet manager can act on."""
        if not deviations:
            return "anomalous_pattern"

        leading = deviations[0]

        match (leading["feature"], leading["direction"]):
            case ("tank_fill_ratio", "high"):
                return "overfill"
            case ("efficiency_ratio", "high"):
                return "ghost_refuel"
            case ("efficiency_ratio", "low"):
                return "excess_consumption"
            case ("hours_since_previous", "low"):
                return "rapid_refuel"
            case ("price_deviation", "high"):
                return "price_mismatch"
            case ("cost_per_km", "high"):
                return "cost_outlier"
            case ("distance_per_day", "high"):
                return "implausible_distance"
            case _:
                return "anomalous_pattern"


_service: FraudService | None = None


def get_fraud_service() -> FraudService:
    global _service

    if _service is None:
        _service = FraudService()

    return _service
