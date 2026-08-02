"""The conversational fuel advisor.

The design constraint here is groundedness. A fuel assistant that invents a
price or a station is worse than no assistant at all — the user drives to a
place that does not exist, or refuels on a forecast that was never made. So:

* the model is given a compact factual snapshot assembled by Laravel and is
  instructed, firmly, to answer only from it;
* it is told to say when it does not know, and the prompt gives it explicit
  permission to do so;
* if no API key is configured, the service answers from the snapshot with
  deterministic templates rather than degrading into guesswork.

`grounded=False` in the response tells the client the answer came from the
fallback path so the UI can label it honestly.
"""

from __future__ import annotations

import json
from typing import Any

from app.core.config import get_settings
from app.core.logging import get_logger
from app.schemas.assistant import ChatRequest, ChatResponse

logger = get_logger(__name__)

SYSTEM_PROMPT = """\
You are the Fuel Intelligence Platform advisor, helping motorists and fleet \
operators in the Philippines spend less on fuel.

Rules you must follow:
1. Answer ONLY from the CONTEXT block supplied with the question. It contains \
   the user's vehicles, their recent spend, live nearby prices and this week's \
   price forecast.
2. If the context does not contain what is needed, say so plainly and suggest \
   what the user could do in the app to get it. Never invent a price, a \
   station name, a distance or a forecast.
3. Quote money as ₱ with two decimals, volume in litres, distance in \
   kilometres, and efficiency as km/L.
4. Be concrete. "Refuel today at SEAOIL BGC — ₱58.50/L saves you about ₱58 on \
   a full tank" beats "prices vary, consider shopping around".
5. Keep answers under 120 words unless the user asks for detail. Lead with the \
   recommendation, then the reason.
6. When you cite a forecast, state its confidence. A 55%-confidence call is \
   not the same as an 85% one and the user deserves to know which they have.
7. Never give advice about driving dangerously to save fuel, and never suggest \
   tampering with a vehicle, an odometer or a fuel system.
"""

DEFAULT_SUGGESTIONS = [
    "Should I refuel today?",
    "Where is the cheapest diesel near me?",
    "Why did my fuel consumption increase?",
    "How much did I spend on fuel last month?",
]


class AssistantService:
    def __init__(self) -> None:
        self.settings = get_settings()
        self._client = self._build_client()

    # ------------------------------------------------------------------ API

    async def chat(self, request: ChatRequest) -> ChatResponse:
        if self._client is None:
            return self._answer_without_model(request)

        messages = self._build_messages(request)

        try:
            completion = await self._client.chat.completions.create(
                model=self.settings.openai_model,
                messages=messages,
                max_tokens=self.settings.openai_max_tokens,
                temperature=0.3,          # low: this is advice, not creative writing
                timeout=self.settings.openai_timeout,
            )
        except Exception as exc:  # noqa: BLE001
            logger.warning("assistant_completion_failed", error=str(exc))

            return self._answer_without_model(request)

        answer = (completion.choices[0].message.content or "").strip()
        tokens = getattr(completion.usage, "total_tokens", None)

        return ChatResponse(
            answer=answer or "I could not work that one out — could you rephrase?",
            suggestions=self._contextual_suggestions(request.context),
            sources=self._sources(request.context),
            tokens=tokens,
            model_used=self.settings.openai_model,
            grounded=True,
        )

    # ------------------------------------------------------- prompt assembly

    def _build_messages(self, request: ChatRequest) -> list[dict[str, str]]:
        messages: list[dict[str, str]] = [{"role": "system", "content": SYSTEM_PROMPT}]

        # Trailing turns only: the whole transcript is rarely relevant and
        # every extra token costs latency and money.
        for turn in request.history[-10:]:
            if turn.role in {"user", "assistant"}:
                messages.append({"role": turn.role, "content": turn.content})

        context_block = json.dumps(self._trim_context(request.context), indent=2, default=str)

        messages.append(
            {
                "role": "user",
                "content": f"CONTEXT:\n{context_block}\n\nQUESTION: {request.question}",
            }
        )

        return messages

    @staticmethod
    def _trim_context(context: dict[str, Any]) -> dict[str, Any]:
        """Keep the snapshot small enough to stay cheap and focused."""
        trimmed = dict(context)

        if isinstance(trimmed.get("nearby_cheapest"), list):
            trimmed["nearby_cheapest"] = trimmed["nearby_cheapest"][:5]

        if isinstance(trimmed.get("vehicles"), list):
            trimmed["vehicles"] = trimmed["vehicles"][:5]

        return trimmed

    # -------------------------------------------------------- fallback path

    def _answer_without_model(self, request: ChatRequest) -> ChatResponse:
        """Deterministic answers built directly from the context snapshot.

        This path runs when no OpenAI key is configured or the API call fails.
        It handles the questions users actually ask most, and is explicit when
        it cannot help rather than producing filler.
        """
        question = request.question.lower()
        context = request.context

        if any(phrase in question for phrase in ("refuel", "fill up", "gas up", "should i buy")):
            answer = self._refuel_answer(context)
        elif any(phrase in question for phrase in ("cheap", "lowest", "best price", "where")):
            answer = self._cheapest_answer(context)
        elif any(phrase in question for phrase in ("spend", "spent", "cost", "expense", "budget")):
            answer = self._spend_answer(context)
        elif any(phrase in question for phrase in ("consumption", "efficiency", "km/l", "mileage")):
            answer = self._efficiency_answer(context)
        else:
            answer = (
                "I can help with refuelling timing, finding cheaper stations nearby, "
                "your fuel spend and your vehicle's efficiency. Ask me one of those "
                "and I will work from your own data."
            )

        return ChatResponse(
            answer=answer,
            suggestions=self._contextual_suggestions(context),
            sources=self._sources(context),
            model_used="rule-based-fallback",
            grounded=False,
        )

    @staticmethod
    def _refuel_answer(context: dict[str, Any]) -> str:
        forecasts = context.get("forecasts") or []

        if not forecasts:
            return (
                "There is no confident price forecast for this week, so refuel whenever "
                "it suits you. I will alert you as soon as a move is expected."
            )

        forecast = forecasts[0]
        direction = forecast.get("direction")
        change = abs(float(forecast.get("change_amount") or 0))
        confidence = int(float(forecast.get("confidence") or 0) * 100)

        if direction == "increase":
            return (
                f"Refuel before Tuesday. A ₱{change:.2f}/L increase is expected on "
                f"{forecast.get('fuel_type', 'fuel')} ({confidence}% confidence). "
                f"On a 50 L tank that is about ₱{change * 50:.0f} saved by filling now."
            )

        if direction == "rollback":
            return (
                f"Hold off if your tank allows. A ₱{change:.2f}/L rollback is expected "
                f"on {forecast.get('fuel_type', 'fuel')} ({confidence}% confidence) — "
                f"roughly ₱{change * 50:.0f} on a 50 L fill."
            )

        return "Prices are expected to hold this week, so refuel when convenient."

    @staticmethod
    def _cheapest_answer(context: dict[str, Any]) -> str:
        stations = context.get("nearby_cheapest") or []

        if not stations:
            return (
                "I do not have your location yet. Enable location access, or open the "
                "map, and I will list the cheapest stations within your alert radius."
            )

        best = stations[0]
        lines = [
            f"Cheapest nearby is {best.get('station')} ({best.get('brand')}) at "
            f"₱{float(best.get('price', 0)):.2f}/L, {float(best.get('distance_km', 0)):.1f} km away."
        ]

        if len(stations) > 1:
            runners = ", ".join(
                f"{station.get('station')} ₱{float(station.get('price', 0)):.2f}"
                for station in stations[1:3]
            )
            lines.append(f"Also close by: {runners}.")

        return " ".join(lines)

    @staticmethod
    def _spend_answer(context: dict[str, Any]) -> str:
        spend = context.get("spend_last_30_days") or {}

        if not spend or not spend.get("fill_ups"):
            return "You have not logged any fill-ups in the last 30 days, so I have nothing to total up yet."

        total = float(spend.get("total_cost") or 0)
        litres = float(spend.get("total_litres") or 0)
        fills = int(spend.get("fill_ups") or 0)
        average = spend.get("avg_price_per_litre")

        sentence = (
            f"Over the last 30 days you spent ₱{total:,.2f} on {litres:,.1f} L "
            f"across {fills} fill-up{'s' if fills != 1 else ''}."
        )

        if average:
            sentence += f" Your average was ₱{float(average):.2f}/L."

        return sentence

    @staticmethod
    def _efficiency_answer(context: dict[str, Any]) -> str:
        vehicles = context.get("vehicles") or []

        if not vehicles:
            return "Add a vehicle and log a couple of full-tank fill-ups, and I can track its efficiency."

        vehicle = vehicles[0]
        current = vehicle.get("avg_km_per_litre")

        if not current:
            return (
                f"{vehicle.get('name')} does not have enough full-tank fill-ups yet. "
                "Log two consecutive full tanks and I can calculate km/L."
            )

        return (
            f"{vehicle.get('name')} is averaging {float(current):.1f} km/L. "
            "If that has dropped recently, the usual causes are tyre pressure, a "
            "clogged air filter, extra load or more stop-start traffic."
        )

    # -------------------------------------------------------------- helpers

    @staticmethod
    def _contextual_suggestions(context: dict[str, Any]) -> list[str]:
        suggestions: list[str] = []

        if context.get("forecasts"):
            suggestions.append("Should I refuel today?")

        if context.get("nearby_cheapest"):
            suggestions.append("Show me cheaper stations nearby")

        if context.get("vehicles"):
            suggestions.append("Why did my fuel consumption increase?")

        if context.get("spend_last_30_days"):
            suggestions.append("How much did I spend on fuel last month?")

        return suggestions or DEFAULT_SUGGESTIONS

    @staticmethod
    def _sources(context: dict[str, Any]) -> list[str]:
        """Name where the answer's facts came from, so the UI can show it."""
        sources: list[str] = []

        if context.get("forecasts"):
            sources.append("FIP weekly price forecast")

        if context.get("nearby_cheapest"):
            sources.append("Live station prices")

        if context.get("spend_last_30_days"):
            sources.append("Your fuel expense log")

        if context.get("vehicles"):
            sources.append("Your vehicle records")

        return sources

    def _build_client(self):
        if not self.settings.openai_api_key:
            logger.info("openai_not_configured_using_fallback")
            return None

        try:
            from openai import AsyncOpenAI

            return AsyncOpenAI(
                api_key=self.settings.openai_api_key,
                timeout=self.settings.openai_timeout,
            )
        except ImportError:
            logger.warning("openai_sdk_missing")
            return None


_service: AssistantService | None = None


def get_assistant_service() -> AssistantService:
    global _service

    if _service is None:
        _service = AssistantService()

    return _service
