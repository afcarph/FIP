<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\Models\OcrScan;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Pricing\Services\CrowdReportService;
use App\Domain\Pricing\Services\OcrScanService;
use App\Http\Controllers\Controller;
use App\Http\Resources\OcrScanResource;
use App\Http\Resources\PriceReportResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Admin — Moderation", description="Approval queues for crowd reports and OCR scans")
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly CrowdReportService $reports,
        private readonly OcrScanService $ocr,
    ) {}

    /**
     * @OA\Get(path="/admin/moderation/queue", tags={"Admin — Moderation"}, security={{"bearerAuth":{}}},
     *   summary="Pending crowd reports", @OA\Response(response=200, description="Queue"))
     */
    public function queue(Request $request): JsonResponse
    {
        $paginator = PriceReport::query()
            ->pending()
            ->with(['station.brand', 'station.city', 'fuelType', 'user:id,first_name,last_name,email'])
            ->orderByDesc('trust_score')
            ->orderBy('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($paginator, PriceReportResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/admin/moderation/reports/{report}/approve", tags={"Admin — Moderation"},
     *   security={{"bearerAuth":{}}}, summary="Approve a report and publish its price",
     *   @OA\Response(response=200, description="Approved"))
     */
    public function approve(Request $request, PriceReport $report): JsonResponse
    {
        $this->authorize('moderate', PriceReport::class);

        return ApiResponse::success(
            new PriceReportResource($this->reports->approve($report, $request->user())),
            'Report approved and published.',
        );
    }

    /**
     * @OA\Post(path="/admin/moderation/reports/{report}/reject", tags={"Admin — Moderation"},
     *   security={{"bearerAuth":{}}}, summary="Reject a report",
     *   @OA\Response(response=200, description="Rejected"))
     */
    public function reject(Request $request, PriceReport $report): JsonResponse
    {
        $this->authorize('moderate', PriceReport::class);

        $request->validate(['reason' => ['required', 'string', 'max:180']]);

        return ApiResponse::success(
            new PriceReportResource($this->reports->reject($report, $request->user(), $request->string('reason')->toString())),
            'Report rejected.',
        );
    }

    /**
     * @OA\Get(path="/admin/moderation/ocr", tags={"Admin — Moderation"}, security={{"bearerAuth":{}}},
     *   summary="OCR scans awaiting review", @OA\Response(response=200, description="Scans"))
     */
    public function ocrQueue(Request $request): JsonResponse
    {
        $paginator = OcrScan::query()
            ->awaitingReview()
            ->with(['user:id,first_name,last_name', 'station.brand'])
            ->orderBy('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($paginator, OcrScanResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/admin/moderation/ocr/{scan}/approve", tags={"Admin — Moderation"},
     *   security={{"bearerAuth":{}}},
     *   summary="Approve an OCR scan, optionally correcting the extracted lines",
     *   @OA\Response(response=200, description="Approved and prices published"))
     */
    public function approveOcr(Request $request, OcrScan $scan): JsonResponse
    {
        $this->authorize('moderate', PriceReport::class);

        $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:gas_stations,id'],
            'lines' => ['nullable', 'array'],
            'lines.*.fuel_type_id' => ['required_with:lines', 'integer', 'exists:fuel_types,id'],
            'lines.*.price' => ['required_with:lines', 'numeric', 'min:0.01', 'max:999.99'],
        ]);

        if ($request->has('station_id')) {
            $scan->update(['station_id' => $request->integer('station_id')]);
        }

        return ApiResponse::success(
            new OcrScanResource($this->ocr->approve($scan, $request->user(), $request->input('lines'))),
            'Scan approved and prices published.',
        );
    }

    /**
     * @OA\Post(path="/admin/moderation/ocr/{scan}/reject", tags={"Admin — Moderation"},
     *   security={{"bearerAuth":{}}}, summary="Reject an OCR scan",
     *   @OA\Response(response=200, description="Rejected"))
     */
    public function rejectOcr(Request $request, OcrScan $scan): JsonResponse
    {
        $this->authorize('moderate', PriceReport::class);

        $request->validate(['reason' => ['required', 'string', 'max:180']]);

        return ApiResponse::success(
            new OcrScanResource($this->ocr->reject($scan, $request->user(), $request->string('reason')->toString())),
            'Scan rejected.',
        );
    }
}
