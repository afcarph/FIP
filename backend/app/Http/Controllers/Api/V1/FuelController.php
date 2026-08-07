<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Doe\Models\DoePrice;
use App\Domain\Doe\Services\FuelQueryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DoePriceResource;
use App\Http\Resources\DoeStationResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Public read API over the scraped DOE prices.
 *
 * These sit alongside the platform's own /prices and /stations endpoints
 * rather than replacing them. The distinction is provenance: /stations is the
 * platform's directory, which operators maintain and users report against;
 * /fuel is what the DOE published, unedited. A user asking "is this the
 * official price?" is asking about this endpoint.
 *
 * @OA\Tag(name="DOE Fuel", description="Fuel prices collected from the DOE dashboard")
 */
class FuelController extends Controller
{
    public function __construct(private readonly FuelQueryService $fuel) {}

    /**
     * @OA\Get(path="/fuel/latest", tags={"DOE Fuel"}, summary="Most recent prices",
     *
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string", enum={"ron91","ron95","ron97","ron100","diesel","diesel_plus"})),
     *   @OA\Parameter(name="city", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="province", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="company", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", maximum=100)),
     *
     *   @OA\Response(response=200, description="Latest prices with national statistics"))
     */
    public function latest(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        try {
            $paginator = $this->fuel->latest($filters, $this->perPage($request));

            // The summary travels with the page. A client showing "cheapest
            // nearby" needs the national figures to say whether it is a good
            // price, and making that a second round trip means the two can
            // disagree when a run lands between them.
            $meta = [
                'as_of' => $this->fuel->latestDate()?->toDateString(),
                'statistics' => $this->fuel->statistics(
                    $filters['fuel'] ?? 'diesel',
                    null,
                    $filters,
                ),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::paginated(
            $paginator,
            DoePriceResource::collection($paginator),
            $meta,
        );
    }

    /**
     * @OA\Get(path="/fuel/history", tags={"DOE Fuel"}, summary="Price history",
     *
     *   @OA\Parameter(name="station_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="date_to", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="days", in="query", @OA\Schema(type="integer", default=30)),
     *
     *   @OA\Response(response=200, description="Historical prices and the national trend"))
     */
    public function history(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        try {
            $paginator = $this->fuel->history($filters, $this->perPage($request, 50));

            $meta = [
                'trend' => $this->fuel->trend(
                    $filters['fuel'] ?? 'diesel',
                    (int) $request->integer('days', 30),
                    $filters,
                ),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::paginated(
            $paginator,
            DoePriceResource::collection($paginator),
            $meta,
        );
    }

    /**
     * @OA\Get(path="/fuel/stations", tags={"DOE Fuel"}, summary="Station directory",
     *
     *   @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="company", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="city", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="province", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="with_coordinates", in="query", @OA\Schema(type="boolean")),
     *
     *   @OA\Response(response=200, description="Paginated stations"))
     */
    public function stations(Request $request): JsonResponse
    {
        $paginator = $this->fuel->stations($this->filters($request), $this->perPage($request));

        return ApiResponse::paginated($paginator, DoeStationResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/fuel/search", tags={"DOE Fuel"}, summary="Search prices",
     *
     *   @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="province", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="city", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="municipality", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="barangay", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="station", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="company", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="min_price", in="query", @OA\Schema(type="number")),
     *   @OA\Parameter(name="max_price", in="query", @OA\Schema(type="number")),
     *   @OA\Parameter(name="date", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *
     *   @OA\Response(response=200, description="Matching prices"),
     *   @OA\Response(response=422, description="Unknown fuel type"))
     */
    public function search(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        try {
            $paginator = $this->fuel->search($filters, $this->perPage($request));
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::paginated(
            $paginator,
            DoePriceResource::collection($paginator),
            ['as_of' => $this->fuel->latestDate()?->toDateString()],
        );
    }

    /**
     * @OA\Get(path="/fuel/company/{company}", tags={"DOE Fuel"},
     *   summary="Prices for one company",
     *
     *   @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="That company's latest prices and its averages"))
     */
    public function company(Request $request, string $company): JsonResponse
    {
        $filters = [...$this->filters($request), 'company' => $company];

        try {
            $paginator = $this->fuel->latest($filters, $this->perPage($request));
            $fuel = $filters['fuel'] ?? 'diesel';

            $meta = [
                'company' => $company,
                'as_of' => $this->fuel->latestDate()?->toDateString(),
                'statistics' => $this->fuel->statistics($fuel, null, $filters),
                'weekly_change' => $this->fuel->change($fuel, 7, $filters),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::paginated(
            $paginator,
            DoePriceResource::collection($paginator),
            $meta,
        );
    }

    /**
     * @OA\Get(path="/fuel/city/{city}", tags={"DOE Fuel"}, summary="Prices in one city",
     *
     *   @OA\Parameter(name="city", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="That city's prices, statistics and extremes"))
     */
    public function city(Request $request, string $city): JsonResponse
    {
        $filters = [...$this->filters($request), 'city' => $city];

        try {
            $paginator = $this->fuel->latest($filters, $this->perPage($request));
            $fuel = $filters['fuel'] ?? 'diesel';
            $extremes = $this->fuel->extremes($fuel, null, $filters);

            $meta = [
                'city' => $city,
                'as_of' => $this->fuel->latestDate()?->toDateString(),
                'statistics' => $this->fuel->statistics($fuel, null, $filters),
                'cheapest' => $extremes['cheapest']
                    ? new DoePriceResource($extremes['cheapest']->loadMissing('station'))
                    : null,
                'most_expensive' => $extremes['most_expensive']
                    ? new DoePriceResource($extremes['most_expensive']->loadMissing('station'))
                    : null,
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::paginated(
            $paginator,
            DoePriceResource::collection($paginator),
            $meta,
        );
    }

    /**
     * @OA\Get(path="/fuel/compare", tags={"DOE Fuel"}, summary="Compare stations side by side",
     *
     *   @OA\Parameter(name="stations", in="query", required=true,
     *     description="Comma-separated station ids, at least two",
     *
     *     @OA\Schema(type="string", example="12,48,193")),
     *
     *   @OA\Parameter(name="date", in="query", @OA\Schema(type="string", format="date")),
     *
     *   @OA\Response(response=200, description="One entry per station, cheapest grade marked"),
     *   @OA\Response(response=422, description="Fewer than two stations given"))
     */
    public function compare(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('stations', '')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            // Bounded: the comparison loads a row per station and marks a
            // winner per grade, and an unbounded list is a cheap way to make
            // the endpoint expensive.
            ->take(10)
            ->values()
            ->all();

        if (count($ids) < 2) {
            return ApiResponse::error(
                'invalid_comparison',
                'Give at least two station ids, comma separated: ?stations=12,48',
                422,
            );
        }

        $date = $request->filled('date') ? Carbon::parse((string) $request->query('date')) : null;
        $comparison = $this->fuel->compare($ids, $date);

        if ($comparison->isEmpty()) {
            return ApiResponse::success([], 'No prices recorded for those stations on that date.', 200, [
                'requested' => $ids,
                'date' => $date?->toDateString() ?? $this->fuel->latestDate()?->toDateString(),
            ]);
        }

        return ApiResponse::success($comparison, null, 200, [
            'date' => $date?->toDateString() ?? $this->fuel->latestDate()?->toDateString(),
            'requested' => $ids,
            // Which of the requested ids came back. A station with no price on
            // that date is silently absent otherwise, and the client cannot
            // tell that from an id that does not exist.
            'missing' => array_values(array_diff(
                $ids,
                $comparison->pluck('station.id')->filter()->all(),
            )),
        ]);
    }

    /**
     * @OA\Get(path="/fuel/insights", tags={"DOE Fuel"},
     *   summary="National statistics, rankings and movement",
     *
     *   @OA\Parameter(name="fuel", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Everything the dashboard needs in one call"))
     */
    public function insights(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $fuel = $filters['fuel'] ?? 'diesel';

        try {
            $extremes = $this->fuel->extremes($fuel, null, $filters);

            $payload = [
                'as_of' => $this->fuel->latestDate()?->toDateString(),
                'fuel' => $fuel,
                'statistics' => $this->fuel->statistics($fuel, null, $filters),
                'cheapest_station' => $extremes['cheapest']
                    ? new DoePriceResource($extremes['cheapest']->loadMissing('station'))
                    : null,
                'most_expensive_station' => $extremes['most_expensive']
                    ? new DoePriceResource($extremes['most_expensive']->loadMissing('station'))
                    : null,
                'province_ranking' => $this->fuel->ranking('province', $fuel),
                'city_ranking' => $this->fuel->ranking('city', $fuel),
                'trend' => $this->fuel->trend($fuel, (int) $request->integer('days', 30), $filters),
                'changes' => $this->fuel->changes($filters),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->badFuel($exception);
        }

        return ApiResponse::success($payload);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter($request->only([
            'q', 'fuel', 'company', 'region', 'province', 'city', 'municipality',
            'barangay', 'station', 'station_id', 'min_price', 'max_price',
            'date', 'date_from', 'date_to', 'sort', 'with_coordinates',
        ]), fn ($value): bool => $value !== null && $value !== '');
    }

    private function perPage(Request $request, int $default = 25): int
    {
        // Capped: these endpoints are public and unauthenticated, and a
        // per_page of 100000 over a national table is a free denial of service.
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    private function badFuel(InvalidArgumentException $exception): JsonResponse
    {
        return ApiResponse::error('invalid_fuel_type', $exception->getMessage(), 422, [
            'accepted' => DoePrice::FUELS,
        ]);
    }
}
