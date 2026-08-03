<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\Models\OcrScan;
use App\Domain\Pricing\Services\OcrScanService;
use App\Domain\Station\Models\GasStation;
use App\Http\Controllers\Controller;
use App\Http\Resources\OcrScanResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="OCR", description="Price board scanning")
 */
class OcrController extends Controller
{
    public function __construct(private readonly OcrScanService $ocr) {}

    /**
     * @OA\Post(path="/ocr/scan", tags={"OCR"}, security={{"bearerAuth":{}}},
     *   summary="Upload a price board photo for extraction",
     *
     *   @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data",
     *
     *     @OA\Schema(required={"image"},
     *
     *       @OA\Property(property="image", type="string", format="binary"),
     *       @OA\Property(property="station_id", type="integer")))),
     *
     *   @OA\Response(response=201, description="Scan result with per-line confidence"),
     *   @OA\Response(response=422, description="Unsupported or oversized image"),
     *   @OA\Response(response=503, description="AI service unavailable"))
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:8192'],
            'station_id' => ['nullable', 'integer', 'exists:gas_stations,id'],
        ]);

        $station = $request->has('station_id')
            ? GasStation::find($request->integer('station_id'))
            : null;

        $scan = $this->ocr->scan($request->user(), $request->file('image'), $station);

        return ApiResponse::created(
            new OcrScanResource($scan),
            $scan->status === OcrScan::STATUS_APPROVED
                ? 'Prices published from your scan.'
                : 'Scan processed — review the extracted prices before submitting.',
        );
    }

    /**
     * @OA\Get(path="/ocr/scans", tags={"OCR"}, security={{"bearerAuth":{}}},
     *   summary="The caller's scan history", @OA\Response(response=200, description="Scans"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = OcrScan::query()
            ->where('user_id', $request->user()->getKey())
            ->with('station')
            ->latest()
            ->paginate(20);

        return ApiResponse::paginated($paginator, OcrScanResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/ocr/scans/{scan}", tags={"OCR"}, security={{"bearerAuth":{}}},
     *   summary="Scan detail", @OA\Response(response=200, description="Scan"))
     */
    public function show(Request $request, OcrScan $scan): JsonResponse
    {
        abort_unless(
            $scan->user_id === $request->user()->getKey() || $request->user()->can('prices.moderate'),
            403,
        );

        return ApiResponse::success(new OcrScanResource($scan->load('station', 'reviewer')));
    }
}
