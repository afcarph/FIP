"""Anomaly detection endpoint."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.logging import get_logger
from app.core.security import verify_service_token
from app.schemas.fraud import FraudRequest, FraudResponse
from app.services.fraud_service import FraudService, get_fraud_service

router = APIRouter(prefix="/detect", tags=["detection"], dependencies=[Depends(verify_service_token)])
logger = get_logger(__name__)


@router.post("/fraud", response_model=FraudResponse, summary="Score transactions for anomalies")
async def detect_fraud(
    request: FraudRequest,
    service: FraudService = Depends(get_fraud_service),
) -> FraudResponse:
    """Score a batch of fuel transactions with an isolation forest.

    Complements the deterministic rules in the Laravel API: those catch single
    bad transactions, this catches patterns that only appear across a fleet's
    whole recent history.
    """
    response = service.detect(request)

    logger.info(
        "fraud_batch_scored",
        submitted=len(request.transactions),
        scored=response.samples_scored,
        flagged=len(response.results),
        model=response.model_used,
    )

    return response
