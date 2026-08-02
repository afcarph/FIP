"""API v1 router aggregation."""

from fastapi import APIRouter

from app.api.v1 import assistant, detect, ocr, optimize, predict

api_router = APIRouter(prefix="/api/v1")

api_router.include_router(predict.router)
api_router.include_router(ocr.router)
api_router.include_router(assistant.router)
api_router.include_router(detect.router)
api_router.include_router(optimize.router)
