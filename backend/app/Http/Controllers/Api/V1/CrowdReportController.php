<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Pricing\Services\CrowdReportService;
use App\Domain\Station\Models\GasStation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePriceReportRequest;
use App\Http\Resources\PriceReportResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Crowd Reports", description="Community price, shortage and queue reports")
 */
class CrowdReportController extends Controller
{
    public function __construct(private readonly CrowdReportService $reports) {}

    /**
     * @OA\Get(path="/reports", tags={"Crowd Reports"}, summary="Recent community reports",
     *
     *   @OA\Parameter(name="station_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="report_type", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Paginated reports"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = PriceReport::query()
            ->published()
            ->when($request->has('station_id'), fn ($q) => $q->where('station_id', $request->integer('station_id')))
            ->when($request->has('report_type'), fn ($q) => $q->where('report_type', $request->string('report_type')->toString()))
            ->with(['station.brand', 'fuelType', 'user:id,first_name,last_name'])
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator, PriceReportResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/reports", tags={"Crowd Reports"}, security={{"bearerAuth":{}}},
     *   summary="Submit a community report",
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"station_id","report_type"},
     *
     *     @OA\Property(property="station_id", type="integer"),
     *     @OA\Property(property="report_type", type="string", enum={"price","shortage","closure","long_queue","wrong_info"}),
     *     @OA\Property(property="fuel_type_id", type="integer"),
     *     @OA\Property(property="price", type="number", format="float"),
     *     @OA\Property(property="latitude", type="number", format="float"),
     *     @OA\Property(property="longitude", type="number", format="float")
     *   )),
     *
     *   @OA\Response(response=201, description="Submitted; may be published immediately"),
     *   @OA\Response(response=422, description="Outside the geofence or price band"))
     */
    public function store(StorePriceReportRequest $request): JsonResponse
    {
        $station = GasStation::findOrFail($request->integer('station_id'));

        $report = $this->reports->submit($request->user(), $station, $request->validated());

        return ApiResponse::created(
            new PriceReportResource($report->load('station', 'fuelType')),
            $report->isPublished()
                ? 'Thanks — your report is live.'
                : 'Thanks — your report is queued for review.',
        );
    }

    /**
     * @OA\Post(path="/reports/{report}/vote", tags={"Crowd Reports"}, security={{"bearerAuth":{}}},
     *   summary="Up or down vote a report", @OA\Response(response=200, description="Vote recorded"))
     */
    public function vote(Request $request, PriceReport $report): JsonResponse
    {
        $request->validate(['vote' => ['required', 'integer', 'in:-1,1']]);

        $updated = $this->reports->vote($report, $request->user(), (int) $request->integer('vote'));

        return ApiResponse::success([
            'upvotes' => $updated->upvotes,
            'downvotes' => $updated->downvotes,
            'status' => $updated->status,
        ]);
    }

    /**
     * @OA\Get(path="/reports/mine", tags={"Crowd Reports"}, security={{"bearerAuth":{}}},
     *   summary="The caller's own submissions and their moderation state",
     *
     *   @OA\Response(response=200, description="Reports"))
     */
    public function mine(Request $request): JsonResponse
    {
        $paginator = $request->user()->priceReports()
            ->with(['station.brand', 'fuelType'])
            ->latest()
            ->paginate(20);

        return ApiResponse::paginated($paginator, PriceReportResource::collection($paginator), [
            'trust_score' => $request->user()->trustScore(),
        ]);
    }
}
