"""Read a fuel receipt into the fields a fill-up record needs.

A receipt is a different OCR problem from a price board. Boards are a handful
of large high-contrast digits; receipts are dense thermal print, often faded,
where the difficulty is not *reading* the characters but working out which
number is the volume, which is the unit price and which is the total.

So the image-to-text stage is deliberately shared with OcrService — the
multi-pass preprocessing there is the expensive part and is equally right for
both — and only the parsing differs.

The parser leans on an arithmetic identity rather than on labels alone:

    litres x price_per_litre = total_cost

Any two of those three imply the third, which turns a partly-legible receipt
into a complete one and, when all three are read, provides a check that catches
a misplaced decimal point. That check is the whole reason this is worth doing:
OCR misreading "42.30" as "4230" is common, and a value that fails the identity
is far more useful flagged than passed downstream.

This service never writes anything and never decides a purchase happened. It
reports what it saw, with per-field confidence, and Laravel decides.
"""

from __future__ import annotations

import re
import time
from datetime import datetime

from app.core.logging import get_logger
from app.schemas.ocr import ReceiptField, ReceiptResponse
from app.services.ocr_service import FUEL_LABEL_PATTERNS, OcrService, get_ocr_service

logger = get_logger(__name__)

# Plausibility bands. A pump transaction outside these is a misread, not a sale:
# Philippine pumps price between roughly P25 and P200 a litre, a tank fill is
# rarely under a litre or over a tanker's worth, and a receipt total in the
# millions is a decimal point in the wrong place.
MIN_PRICE_PER_LITRE = 25.0
MAX_PRICE_PER_LITRE = 200.0
MIN_LITRES = 0.5
MAX_LITRES = 1000.0
MAX_TOTAL = 200_000.0

# How far the three figures may disagree before the reading is suspect. Pumps
# round to the centavo and receipts print the rounded figures, so a small
# mismatch is normal and a large one is not.
CONSISTENCY_TOLERANCE = 0.02

_NUMBER = r"(\d[\d,\s]*(?:\.\d+)?)"

LITRE_PATTERNS = [
    re.compile(rf"(?:lit(?:re|er)s?|ltr|vol(?:ume)?|qty|quantity)\b\D{{0,12}}{_NUMBER}", re.I),
    re.compile(rf"{_NUMBER}\s*(?:l|lit(?:re|er)s?)\b", re.I),
]

PRICE_PATTERNS = [
    re.compile(rf"(?:unit\s*price|price\s*/?\s*l(?:it(?:re|er))?|@|per\s*lit(?:re|er))\D{{0,10}}{_NUMBER}", re.I),
]

TOTAL_PATTERNS = [
    re.compile(rf"(?:amount\s*due|grand\s*total|total\s*amount|total|amount)\b\D{{0,12}}{_NUMBER}", re.I),
]

ODOMETER_PATTERNS = [
    re.compile(rf"(?:odo(?:meter)?|mileage|km\s*reading)\D{{0,12}}{_NUMBER}", re.I),
]

DATE_PATTERNS = [
    (re.compile(r"\b(\d{4})[-/](\d{1,2})[-/](\d{1,2})\b"), "ymd"),
    (re.compile(r"\b(\d{1,2})[-/](\d{1,2})[-/](\d{4})\b"), "dmy"),
    (re.compile(r"\b(\d{1,2})[-/](\d{1,2})[-/](\d{2})\b"), "dmy2"),
]

TIME_PATTERN = re.compile(r"\b(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm)?\b", re.I)

# Station brands seen on Philippine receipts, matched loosely — the value is a
# hint for the operator to confirm, never an automatic station assignment.
BRAND_PATTERNS = [
    (r"\bpetron\b", "Petron"),
    (r"\bshell\b", "Shell"),
    (r"\bcaltex\b", "Caltex"),
    (r"\bphoenix\b", "Phoenix"),
    (r"\bseaoil\b", "SEAOIL"),
    (r"\bunioil\b", "Unioil"),
    (r"\btotal\s*energies\b|\btotalenergies\b", "TotalEnergies"),
    (r"\bcleanfuel\b", "Cleanfuel"),
    (r"\bjetti\b", "Jetti"),
    (r"\bflying\s*v\b", "Flying V"),
]


class ReceiptService:
    """Parses fuel receipts. Shares the recognition stage with OcrService."""

    def __init__(self, ocr: OcrService | None = None) -> None:
        self._ocr = ocr or get_ocr_service()

    def scan(self, image_bytes: bytes) -> ReceiptResponse:
        started = time.perf_counter()
        warnings: list[str] = []

        image = self._ocr._decode(image_bytes)  # noqa: SLF001 — shared pipeline stage

        if image is None:
            return ReceiptResponse(
                raw_text="",
                processing_ms=self._elapsed(started),
                warnings=["Image could not be decoded."],
            )

        if self._ocr._is_blurry(image):  # noqa: SLF001
            warnings.append("The photo looks out of focus; check the extracted figures.")

        text, recognition_confidence, _boxes = self._ocr._recognise(image)  # noqa: SLF001

        if not text.strip():
            return ReceiptResponse(
                raw_text="",
                processing_ms=self._elapsed(started),
                warnings=["No readable text was found on the receipt."],
            )

        litres = self._match(text, LITRE_PATTERNS, MIN_LITRES, MAX_LITRES)
        price = self._match(text, PRICE_PATTERNS, MIN_PRICE_PER_LITRE, MAX_PRICE_PER_LITRE)
        total = self._match(text, TOTAL_PATTERNS, 1.0, MAX_TOTAL)
        odometer = self._match(text, ODOMETER_PATTERNS, 0.0, 9_999_999.0)

        litres, price, total, derived = self._reconcile(litres, price, total)
        warnings.extend(derived)

        overall = self._overall(litres, price, total, recognition_confidence)

        response = ReceiptResponse(
            raw_text=text.strip(),
            litres=litres,
            price_per_litre=price,
            total_cost=total,
            purchased_at=self._match_timestamp(text),
            odometer=odometer,
            station_hint=self._match_brand(text),
            fuel_type=self._match_fuel(text),
            overall_confidence=overall,
            processing_ms=self._elapsed(started),
            warnings=warnings,
        )

        logger.info(
            "receipt_scanned",
            bytes=len(image_bytes),
            confidence=overall,
            litres=litres.value,
            total=total.value,
            ms=response.processing_ms,
        )

        return response

    # ------------------------------------------------------------ parsing ---

    def _match(
        self,
        text: str,
        patterns: list[re.Pattern[str]],
        minimum: float,
        maximum: float,
    ) -> ReceiptField:
        """First pattern that yields a number inside the plausible band wins."""
        for pattern in patterns:
            for match in pattern.finditer(text):
                value = self._to_float(match.group(1))

                if value is None or not (minimum <= value <= maximum):
                    continue

                # A labelled figure is worth more than a bare one: the label is
                # what tells us the number is a volume rather than a price.
                return ReceiptField(
                    value=round(value, 3),
                    confidence=0.75,
                    source_text=match.group(0).strip(),
                )

        return ReceiptField()

    def _reconcile(
        self,
        litres: ReceiptField,
        price: ReceiptField,
        total: ReceiptField,
    ) -> tuple[ReceiptField, ReceiptField, ReceiptField, list[str]]:
        """Use litres x price = total to fill a gap, or to catch a bad read."""
        warnings: list[str] = []
        found = [field for field in (litres, price, total) if field.value is not None]

        if len(found) == 3:
            expected = float(litres.value) * float(price.value)  # type: ignore[arg-type]
            actual = float(total.value)  # type: ignore[arg-type]
            drift = abs(expected - actual) / max(actual, 1.0)

            if drift <= CONSISTENCY_TOLERANCE:
                # Three figures that agree corroborate each other, so each is
                # more trustworthy than it was alone.
                for field in (litres, price, total):
                    field.confidence = min(0.98, field.confidence + 0.2)
            else:
                warnings.append(
                    f"Litres x price does not match the total (off by {drift:.0%}); "
                    "check for a misread decimal point.",
                )
                for field in (litres, price, total):
                    field.confidence = max(0.1, field.confidence - 0.35)

            return litres, price, total, warnings

        if len(found) == 2:
            if litres.value is None and price.value and total.value:
                litres = self._derived(float(total.value) / float(price.value), 3)
                warnings.append("Litres were derived from the total and unit price.")
            elif price.value is None and litres.value and total.value:
                price = self._derived(float(total.value) / float(litres.value), 4)
                warnings.append("Unit price was derived from the total and litres.")
            elif total.value is None and litres.value and price.value:
                total = self._derived(float(litres.value) * float(price.value), 2)
                warnings.append("The total was derived from litres and unit price.")

            return litres, price, total, warnings

        warnings.append("Too little was readable to reconstruct the transaction.")

        return litres, price, total, warnings

    @staticmethod
    def _derived(value: float, places: int) -> ReceiptField:
        # Lower confidence than a read figure on purpose: arithmetic cannot
        # detect that one of its inputs was wrong.
        return ReceiptField(value=round(value, places), confidence=0.55, source_text="derived")

    def _match_timestamp(self, text: str) -> ReceiptField:
        for pattern, order in DATE_PATTERNS:
            match = pattern.search(text)

            if match is None:
                continue

            try:
                if order == "ymd":
                    year, month, day = (int(match.group(i)) for i in (1, 2, 3))
                elif order == "dmy":
                    day, month, year = (int(match.group(i)) for i in (1, 2, 3))
                else:
                    day, month, short = (int(match.group(i)) for i in (1, 2, 3))
                    year = 2000 + short

                stamp = datetime(year, month, day)
            except ValueError:
                continue

            time_match = TIME_PATTERN.search(text)

            if time_match is not None:
                hour = int(time_match.group(1))
                minute = int(time_match.group(2))
                meridiem = (time_match.group(4) or "").lower()

                if meridiem == "pm" and hour < 12:
                    hour += 12
                elif meridiem == "am" and hour == 12:
                    hour = 0

                if 0 <= hour <= 23 and 0 <= minute <= 59:
                    stamp = stamp.replace(hour=hour, minute=minute)

            return ReceiptField(
                value=stamp.isoformat(),
                confidence=0.7,
                source_text=match.group(0),
            )

        return ReceiptField()

    @staticmethod
    def _match_brand(text: str) -> ReceiptField:
        for pattern, brand in BRAND_PATTERNS:
            match = re.search(pattern, text, re.I)

            if match is not None:
                return ReceiptField(value=brand, confidence=0.8, source_text=match.group(0))

        return ReceiptField()

    @staticmethod
    def _match_fuel(text: str) -> ReceiptField:
        # Reuses the price-board label table: the same brand names for the same
        # grades appear on both, so a second table would only drift from this one.
        for pattern, code in FUEL_LABEL_PATTERNS:
            match = re.search(pattern, text, re.I)

            if match is not None:
                return ReceiptField(value=code, confidence=0.7, source_text=match.group(0))

        return ReceiptField()

    # ----------------------------------------------------------- internals ---

    @staticmethod
    def _to_float(raw: str) -> float | None:
        cleaned = raw.replace(",", "").replace(" ", "").strip()

        try:
            return float(cleaned)
        except ValueError:
            return None

    @staticmethod
    def _overall(
        litres: ReceiptField,
        price: ReceiptField,
        total: ReceiptField,
        fallback: float,
    ) -> float:
        # Only the three transaction figures count towards the headline number.
        # A receipt whose brand and date read perfectly is still useless if the
        # amounts did not.
        scores = [field.confidence for field in (litres, price, total) if field.value is not None]

        return round(sum(scores) / len(scores), 3) if scores else round(min(fallback, 0.3), 3)

    @staticmethod
    def _elapsed(started: float) -> int:
        return int((time.perf_counter() - started) * 1000)


_service: ReceiptService | None = None


def get_receipt_service() -> ReceiptService:
    global _service

    if _service is None:
        _service = ReceiptService()

    return _service
