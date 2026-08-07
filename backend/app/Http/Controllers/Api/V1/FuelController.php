<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use App\Domain\Doe\Models\ImportRun;
use App\Domain\Doe\Services\FuelReportQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\FuelPriceResource;
use App\Http\Resources\FuelReportResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The DOE's published price monitoring reports.
 *
 * These sit alongside the platform's own /prices and /stations rather than
 * replacing them, and they are not the same kind of data. /stations and
 * /prices are per-station pump prices the platform maintains. This is what the
 * Department of Energy published for a city, a brand and a week — a min-max
 * range and a common price. Nothing here is a station price, and nothing here
 * duplicates a table the platform already had.
 *
 * @OA\Tag(name="DOE Fuel Reports", description="Weekly DOE price monitoring publications")
 */
class FuelController extends Controller
{
    public function __construct(private readonly FuelReportQuery $reports) {}

    /**
     * @OA\Get(path="/fuel/latest", tags={"DOE Fuel Reports"},
     *   summary="Prices from the most recent report per region",
     *
     *   @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="area", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="brand", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel_code", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Latest published prices"))
     */
    public function latest(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $paginator = $this->reports->latestPrices($filters, $this->perPage($request));

        return ApiResponse::paginated(
            $paginator,
            FuelPriceResource::collection($paginator),
            [
                // Which publications these figures came from. A price without
                // its report is a number with no provenance.
                'reports' => FuelReportResource::collection($this->reports->latest($filters)),
            ],
        );
    }

    /**
     * @OA\Get(path="/fuel/history", tags={"DOE Fuel Reports"},
     *   summary="Published prices over time",
     *
     *   @OA\Parameter(name="area", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="brand", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel_code", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to", in="query", @OA\Schema(type="string", format="date")),
     *
     *   @OA\Response(response=200, description="Historical prices"))
     */
    public function history(Request $request): JsonResponse
    {
        $paginator = $this->reports->history($this->filters($request), $this->perPage($request, 100));

        return ApiResponse::paginated($paginator, FuelPriceResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/fuel/areas", tags={"DOE Fuel Reports"},
     *   summary="Cities and municipalities the DOE monitors",
     *
     *   @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Areas, with their region"))
     */
    public function areas(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reports->areas($request->query('region') ? (string) $request->query('region') : null),
        );
    }

    /**
     * @OA\Get(path="/fuel/brands", tags={"DOE Fuel Reports"},
     *   summary="Brands the DOE monitors",
     *
     *   @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Brands, with their coverage"))
     */
    public function brands(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reports->brands($request->query('region') ? (string) $request->query('region') : null),
        );
    }

    /**
     * @OA\Get(path="/fuel/search", tags={"DOE Fuel Reports"}, summary="Search published prices",
     *
     *   @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="area", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="brand", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="product", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel_code", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="min_price", in="query", @OA\Schema(type="number")),
     *   @OA\Parameter(name="max_price", in="query", @OA\Schema(type="number")),
     *   @OA\Parameter(name="branded_only", in="query", @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *
     *   @OA\Response(response=200, description="Matching prices"))
     */
    public function search(Request $request): JsonResponse
    {
        $paginator = $this->reports->search($this->filters($request), $this->perPage($request));

        return ApiResponse::paginated($paginator, FuelPriceResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/fuel/trends", tags={"DOE Fuel Reports"}, summary="A weekly price series",
     *
     *   @OA\Parameter(name="fuel_code", in="query", required=true,
     *
     *     @OA\Schema(type="string", example="gasoline_ron95")),
     *
     *   @OA\Parameter(name="region", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="area", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="weeks", in="query", @OA\Schema(type="integer", default=26)),
     *
     *   @OA\Response(response=200, description="One point per published week"),
     *   @OA\Response(response=422, description="No fuel_code given"))
     */
    public function trends(Request $request): JsonResponse
    {
        $fuelCode = (string) $request->query('fuel_code', '');

        if ($fuelCode === '') {
            // A trend across grades would average diesel with premium petrol,
            // which is not a number that means anything.
            return ApiResponse::error(
                'fuel_code_required',
                'Give a fuel_code, e.g. ?fuel_code=gasoline_ron95',
                422,
            );
        }

        $series = $this->reports->trends(
            $fuelCode,
            $this->filters($request),
            (int) $request->integer('weeks', 26),
        );

        return ApiResponse::success($series, null, 200, [
            'fuel_code' => $fuelCode,
            // Said plainly, because the series is a midpoint of a published
            // range and a caller should not read it as a quoted price.
            'note' => 'Points are the midpoint of the DOE\'s published range; '
                .'lowest and highest bound it.',
        ]);
    }

    /**
     * @OA\Get(path="/fuel/imports", tags={"DOE Fuel Reports"},
     *   summary="Ingestion health, for the admin dashboard",
     *
     *   @OA\Response(response=200, description="Recent runs and the latest report"))
     */
    public function imports(): JsonResponse
    {
        $runs = ImportRun::query()->orderByDesc('started_at')->limit(20)->get();
        $latest = $this->reports->latest();

        $lastGood = $runs->first(fn (ImportRun $run): bool => $run->isHealthy());

        return ApiResponse::success([
            'last_run' => $runs->first(),
            'last_successful_run' => $lastGood,
            'last_import_duration_seconds' => $lastGood?->duration_seconds,
            'failed_runs' => $runs->where('status', ImportRun::STATUS_FAILED)->values(),
            'latest_reports' => FuelReportResource::collection($latest),
            'latest_publication_date' => $latest->max('coverage_start')?->toDateString(),

            // Everything held, not just the current week. `records_total`
            // below sums only the latest report per region, which is the right
            // number for "what is on screen now" and the wrong one for
            // "how much have we imported" — a dashboard asking the second
            // question with the first answer understates by the whole archive.
            'reports_total' => FuelReport::query()->count(),
            'prices_total' => FuelPrice::query()->count(),
            'regions_total' => FuelReport::query()->distinct()->count('region'),
            'oldest_coverage_date' => FuelReport::query()->min('coverage_start'),

            'records_total' => (int) $latest->sum('rows_count'),
            'runs' => $runs,
        ]);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter(
            $request->only([
                'region', 'area', 'province', 'brand', 'product', 'fuel_code',
                'min_price', 'max_price', 'date_from', 'date_to',
                'sort', 'branded_only',
            ]),
            static fn ($value): bool => $value !== null && $value !== '',
        );
    }

    private function perPage(Request $request, int $default = 50): int
    {
        // Capped: these endpoints are public and unauthenticated, and an
        // uncapped page over a national archive is a free denial of service.
        return max(1, min(200, (int) $request->integer('per_page', $default)));
    }
}
