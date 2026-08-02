"""Conversational advisor endpoint."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.core.logging import get_logger
from app.core.security import verify_service_token
from app.schemas.assistant import ChatRequest, ChatResponse
from app.services.assistant_service import AssistantService, get_assistant_service

router = APIRouter(prefix="/assistant", tags=["assistant"], dependencies=[Depends(verify_service_token)])
logger = get_logger(__name__)


@router.post("/chat", response_model=ChatResponse, summary="Ask the fuel advisor")
async def chat(
    request: ChatRequest,
    service: AssistantService = Depends(get_assistant_service),
) -> ChatResponse:
    """Answer one conversational turn, grounded in the supplied context.

    `grounded` is false when the answer came from the deterministic fallback
    rather than the language model, so the client can label it accordingly.
    """
    response = await service.chat(request)

    logger.info(
        "assistant_answered",
        question_length=len(request.question),
        grounded=response.grounded,
        model=response.model_used,
        tokens=response.tokens,
    )

    return response
