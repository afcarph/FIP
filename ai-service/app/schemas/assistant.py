"""Contracts for the conversational fuel advisor."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class ChatTurn(BaseModel):
    role: Literal["system", "user", "assistant", "tool"]
    content: str


class ChatRequest(BaseModel):
    question: str = Field(min_length=3, max_length=1000)
    history: list[ChatTurn] = Field(default_factory=list)
    # Factual snapshot assembled by Laravel: vehicles, forecasts, nearby prices,
    # recent spend. The model is instructed to answer strictly from this.
    context: dict = Field(default_factory=dict)
    user: dict = Field(default_factory=dict)


class ChatResponse(BaseModel):
    answer: str
    suggestions: list[str] = Field(default_factory=list)
    sources: list[str] = Field(default_factory=list)
    tokens: int | None = None
    model_used: str
    grounded: bool = True
