"""Service-to-service authentication.

The AI service is never exposed publicly: it sits on the internal network and
only the Laravel API calls it. A shared bearer token gives defence in depth if
the network boundary is ever misconfigured.
"""

from __future__ import annotations

import hmac

from fastapi import Header, HTTPException, status

from app.core.config import get_settings


async def verify_service_token(x_service_token: str | None = Header(default=None)) -> None:
    """FastAPI dependency guarding every non-public route."""
    settings = get_settings()

    if not settings.service_token:
        # No token configured: acceptable in local development, refused in prod.
        if settings.is_production:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="SERVICE_TOKEN must be configured in production.",
            )
        return

    if x_service_token is None:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing X-Service-Token header.",
        )

    # Constant-time comparison so the header cannot be brute-forced by timing.
    if not hmac.compare_digest(x_service_token, settings.service_token):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid service token.",
        )
