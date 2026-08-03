"""OCR extraction from photographed station price boards.

Price boards are a hostile OCR target: high-contrast LED or flip digits, shot
at an angle, often at night, frequently with glare. Raw Tesseract on such an
image is unreliable, so the pipeline does the work in three stages.

1. **Preprocessing** — several deliberately different variants of the image are
   produced (adaptive threshold, Otsu, inverted, upscaled). LED boards are
   usually light-on-dark, which plain thresholding destroys, so the inverted
   variant matters as much as the standard one.

2. **Multi-pass recognition** — every variant is run through Tesseract and the
   pass with the best mean word confidence wins. This costs more CPU but turns
   a coin-flip into a reliable read.

3. **Structured parsing** — the raw text is matched line by line against known
   fuel labels and price patterns. Anything outside the plausible pump-price
   band is dropped here rather than being passed downstream.

The service never decides whether a price is *correct* for a station — that
validation belongs to Laravel, which knows the local price band.
"""

from __future__ import annotations

import re
import time
from typing import Any

import cv2
import numpy as np
import pytesseract
from PIL import Image

from app.core.config import get_settings
from app.core.logging import get_logger
from app.schemas.ocr import BoundingBox, OcrLine, OcrResponse

logger = get_logger(__name__)

# Philippine pump prices sit roughly between ₱30 and ₱120 per litre. Anything
# outside that band is a misread — a decimal point in the wrong place, or the
# board's litre counter rather than a price.
MIN_PLAUSIBLE_PRICE = 25.0
MAX_PLAUSIBLE_PRICE = 200.0

# Board labels vary by brand; each entry maps display text to a canonical code.
FUEL_LABEL_PATTERNS: list[tuple[str, str]] = [
    (r"\b(xtra\s*advance|blaze\s*100|ron\s*97|super\s*97)\b", "gasoline_ron97"),
    (r"\b(v[\s\-]?power|xcs|silver|blaze|premium\s*95|ron\s*95|gold)\b", "gasoline_ron95"),
    (r"\b(xtra\s*unleaded|unleaded|regular|ron\s*91|xtend)\b", "gasoline_ron91"),
    (r"\b(premium\s*diesel|diesel\s*max|v[\s\-]?power\s*diesel|xtra\s*diesel)\b", "diesel_premium"),
    (r"\b(diesel|gasoil|turbo)\b", "diesel"),
    (r"\b(kerosene|kero)\b", "kerosene"),
    (r"\b(auto\s*lpg|lpg)\b", "lpg_auto"),
]

# Matches 58.90, 58,90, 5890 (missing separator) and ₱58.90.
PRICE_PATTERN = re.compile(r"(?:₱|php)?\s*(\d{1,3})[.,\s]?(\d{2})\b", re.IGNORECASE)


class OcrService:
    def __init__(self) -> None:
        self.settings = get_settings()

        if self.settings.tesseract_cmd:
            pytesseract.pytesseract.tesseract_cmd = self.settings.tesseract_cmd

    # ------------------------------------------------------------------ API

    def scan(self, image_bytes: bytes) -> OcrResponse:
        """Extract fuel/price pairs from a price-board photograph."""
        started = time.perf_counter()
        warnings: list[str] = []

        image = self._decode(image_bytes)

        if image is None:
            return OcrResponse(
                raw_text="",
                lines=[],
                overall_confidence=0.0,
                processing_ms=int((time.perf_counter() - started) * 1000),
                warnings=["Image could not be decoded."],
            )

        if self._is_blurry(image):
            warnings.append("The photo looks out of focus; results may be unreliable.")

        best_text, best_confidence, word_boxes = self._recognise(image)

        if not best_text.strip():
            warnings.append("No readable text was found on the board.")

        lines = self._parse(best_text, word_boxes)

        if not lines:
            warnings.append("Text was read but no fuel/price pairs could be matched.")

        overall = (
            round(sum(line.confidence for line in lines) / len(lines), 3) if lines else 0.0
        )

        return OcrResponse(
            raw_text=best_text.strip(),
            lines=lines,
            engine="tesseract",
            overall_confidence=overall or round(best_confidence, 3),
            processing_ms=int((time.perf_counter() - started) * 1000),
            warnings=warnings,
        )

    # -------------------------------------------------------- preprocessing

    @staticmethod
    def _decode(image_bytes: bytes) -> np.ndarray | None:
        buffer = np.frombuffer(image_bytes, dtype=np.uint8)
        image = cv2.imdecode(buffer, cv2.IMREAD_COLOR)

        if image is None:
            return None

        # Very large phone photos slow Tesseract down without improving the
        # read; cap the long edge at 2000 px.
        height, width = image.shape[:2]
        longest = max(height, width)

        if longest > 2000:
            scale = 2000 / longest
            image = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)

        return image

    @staticmethod
    def _is_blurry(image: np.ndarray, threshold: float = 60.0) -> bool:
        """Variance of the Laplacian — the standard focus measure."""
        grey = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

        return float(cv2.Laplacian(grey, cv2.CV_64F).var()) < threshold

    def _variants(self, image: np.ndarray) -> list[tuple[str, np.ndarray]]:
        """Produce several preprocessed candidates for the recognition pass."""
        grey = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

        # CLAHE lifts digits out of glare far better than global equalisation.
        clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8)).apply(grey)
        denoised = cv2.bilateralFilter(clahe, 9, 75, 75)

        _, otsu = cv2.threshold(denoised, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

        adaptive = cv2.adaptiveThreshold(
            denoised, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 10
        )

        # LED boards are light text on a dark panel — inverting is essential.
        inverted = cv2.bitwise_not(otsu)

        # Upscaling helps Tesseract with the small digits on distant boards.
        upscaled = cv2.resize(otsu, None, fx=1.6, fy=1.6, interpolation=cv2.INTER_CUBIC)

        return [
            ("otsu", otsu),
            ("adaptive", adaptive),
            ("inverted", inverted),
            ("upscaled", upscaled),
            ("greyscale", denoised),
        ]

    # ---------------------------------------------------------- recognition

    def _recognise(self, image: np.ndarray) -> tuple[str, float, list[dict[str, Any]]]:
        """Run every variant and keep the highest-confidence result."""
        best_text = ""
        best_confidence = 0.0
        best_boxes: list[dict[str, Any]] = []

        # PSM 6 assumes a uniform block of text, which matches a price board.
        config = f"--oem 3 --psm 6 -l {self.settings.ocr_languages}"

        for name, variant in self._variants(image):
            try:
                data = pytesseract.image_to_data(
                    Image.fromarray(variant),
                    config=config,
                    output_type=pytesseract.Output.DICT,
                )
            except Exception as exc:
                logger.warning("tesseract_variant_failed", variant=name, error=str(exc))
                continue

            confidences = [
                float(conf)
                for conf, text in zip(data["conf"], data["text"], strict=False)
                if str(conf).lstrip("-").isdigit() and float(conf) >= 0 and text.strip()
            ]

            if not confidences:
                continue

            mean_confidence = sum(confidences) / len(confidences) / 100.0

            if mean_confidence > best_confidence:
                best_confidence = mean_confidence
                best_text = self._reconstruct_lines(data)
                best_boxes = self._extract_boxes(data)

                logger.debug("ocr_variant_best", variant=name, confidence=mean_confidence)

        return best_text, best_confidence, best_boxes

    @staticmethod
    def _reconstruct_lines(data: dict[str, Any]) -> str:
        """Rebuild physical lines from Tesseract's per-word output.

        Layout matters here: a price belongs to the label on the same row, so
        flattening everything into one string would lose the association.
        """
        rows: dict[tuple[int, int, int], list[str]] = {}

        for index, text in enumerate(data["text"]):
            if not text.strip():
                continue

            key = (data["block_num"][index], data["par_num"][index], data["line_num"][index])
            rows.setdefault(key, []).append(text)

        return "\n".join(" ".join(words) for _, words in sorted(rows.items()))

    @staticmethod
    def _extract_boxes(data: dict[str, Any]) -> list[dict[str, Any]]:
        boxes: list[dict[str, Any]] = []

        for index, text in enumerate(data["text"]):
            if not text.strip():
                continue

            boxes.append(
                {
                    "text": text,
                    "line": data["line_num"][index],
                    "block": data["block_num"][index],
                    "x": int(data["left"][index]),
                    "y": int(data["top"][index]),
                    "width": int(data["width"][index]),
                    "height": int(data["height"][index]),
                    "confidence": max(float(data["conf"][index]), 0.0) / 100.0,
                }
            )

        return boxes

    # -------------------------------------------------------------- parsing

    def _parse(self, text: str, boxes: list[dict[str, Any]]) -> list[OcrLine]:
        """Match fuel labels to prices, one physical line at a time."""
        results: list[OcrLine] = []
        seen_fuels: set[str] = set()

        for raw_line in text.split("\n"):
            line = raw_line.strip()

            if not line:
                continue

            fuel_code = self._match_fuel(line)
            price = self._match_price(line)

            if fuel_code is None or price is None:
                continue

            # Boards list each grade once; a repeat is almost always the same
            # row read twice from two preprocessing variants.
            if fuel_code in seen_fuels:
                continue

            seen_fuels.add(fuel_code)

            results.append(
                OcrLine(
                    label=line,
                    fuel_type=fuel_code,
                    price=price,
                    confidence=self._line_confidence(line, boxes),
                    bbox=self._line_bbox(line, boxes),
                )
            )

        return results

    @staticmethod
    def _match_fuel(line: str) -> str | None:
        normalised = re.sub(r"[^a-z0-9\s]", " ", line.lower())

        for pattern, code in FUEL_LABEL_PATTERNS:
            if re.search(pattern, normalised):
                return code

        return None

    @staticmethod
    def _match_price(line: str) -> float | None:
        """Pick the most plausible pump price from a line of text."""
        candidates: list[float] = []

        for whole, decimals in PRICE_PATTERN.findall(line):
            try:
                value = float(f"{whole}.{decimals}")
            except ValueError:
                continue

            if MIN_PLAUSIBLE_PRICE <= value <= MAX_PLAUSIBLE_PRICE:
                candidates.append(value)

        if not candidates:
            return None

        # Where a line holds several numbers (grade number, price, litre count)
        # the largest plausible value is nearly always the price.
        return round(max(candidates), 2)

    @staticmethod
    def _line_confidence(line: str, boxes: list[dict[str, Any]]) -> float:
        """Average word confidence for the words that make up this line."""
        tokens = {token.lower() for token in line.split() if token}

        matched = [box["confidence"] for box in boxes if box["text"].lower() in tokens]

        if not matched:
            return 0.5

        return round(sum(matched) / len(matched), 3)

    @staticmethod
    def _line_bbox(line: str, boxes: list[dict[str, Any]]) -> BoundingBox | None:
        """Union of the word boxes on this line, for the reviewer overlay."""
        tokens = {token.lower() for token in line.split() if token}
        matched = [box for box in boxes if box["text"].lower() in tokens]

        if not matched:
            return None

        left = min(box["x"] for box in matched)
        top = min(box["y"] for box in matched)
        right = max(box["x"] + box["width"] for box in matched)
        bottom = max(box["y"] + box["height"] for box in matched)

        return BoundingBox(x=left, y=top, width=right - left, height=bottom - top)


_service: OcrService | None = None


def get_ocr_service() -> OcrService:
    global _service

    if _service is None:
        _service = OcrService()

    return _service
