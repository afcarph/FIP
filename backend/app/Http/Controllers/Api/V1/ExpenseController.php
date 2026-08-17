<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Services\FuelExpenseService;
use App\Domain\Expense\Services\ReceiptScanService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreFuelPurchaseRequest;
use App\Http\Resources\FuelPurchaseResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @OA\Tag(name="Expenses", description="Fuel purchase log and spend analytics")
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly FuelExpenseService $expenses,
        private readonly ReceiptScanService $receipts,
    ) {}

    /**
     * @OA\Post(path="/expenses/scan-receipt", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="Read a fill-up off a photographed receipt",
     *
     *   @OA\Response(response=200, description="Draft values with confidence; nothing is recorded"),
     *   @OA\Response(response=422, description="Unsupported or oversized image"),
     *   @OA\Response(response=503, description="AI service unavailable"))
     */
    public function scanReceipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:8192'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        $vehicle = null;

        if (isset($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            // Scanning against a vehicle reveals its odometer in the warning
            // text, so it needs the same gate as logging a fill-up would.
            $this->authorize('update', $vehicle);
        }

        $result = $this->receipts->scan($request->file('image'), $vehicle);

        return ApiResponse::success([
            ...$result,
            'station_candidates' => $this->receipts->stationCandidates($result['draft']['station_hint'] ?? null),
        ], 'Receipt scanned — check the figures before saving.');
    }

    /**
     * @OA\Get(path="/expenses", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="List fuel purchases",
     *
     *   @OA\Parameter(name="vehicle_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *
     *   @OA\Response(response=200, description="Paginated purchases"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->scope($request)
            ->when($request->has('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            // Moved into the application's timezone before the day is taken.
            // `purchased_at` is stored as a local wall clock, and a client may
            // send either a plain date or a UTC instant; without this, "16
            // August" from a browser sending Zulu time takes the boundaries of
            // a different day. See FleetController::vehicleLocationHistory,
            // where the same mismatch was eight hours of wrong answers.
            ->when($request->has('from'), fn ($q) => $q->where('purchased_at', '>=', Carbon::parse($request->string('from')->toString())->setTimezone(config('app.timezone'))->startOfDay()))
            ->when($request->has('to'), fn ($q) => $q->where('purchased_at', '<=', Carbon::parse($request->string('to')->toString())->setTimezone(config('app.timezone'))->endOfDay()))
            ->with(['vehicle', 'station.brand', 'fuelType'])
            ->latest('purchased_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator, FuelPurchaseResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/expenses", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="Log a fill-up",
     *
     *   @OA\Response(response=201, description="Recorded, with derived efficiency metrics"),
     *   @OA\Response(response=422, description="Inconsistent totals or odometer rollback"))
     */
    public function store(StoreFuelPurchaseRequest $request): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($request->integer('vehicle_id'));

        $this->authorize('update', $vehicle);

        $purchase = $this->expenses->record($request->user(), $vehicle, $request->validated());

        return ApiResponse::created(new FuelPurchaseResource($purchase->load('vehicle', 'station', 'fuelType')));
    }

    /**
     * @OA\Put(path="/expenses/{purchase}", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="Amend a fill-up", @OA\Response(response=200, description="Updated"))
     */
    public function update(StoreFuelPurchaseRequest $request, FuelPurchase $purchase): JsonResponse
    {
        $this->authorize('update', $purchase);

        $updated = $this->expenses->update($purchase, $request->user(), $request->validated());

        return ApiResponse::success(new FuelPurchaseResource($updated));
    }

    /**
     * @OA\Delete(path="/expenses/{purchase}", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="Delete a fill-up", @OA\Response(response=204, description="Deleted"))
     */
    public function destroy(FuelPurchase $purchase): JsonResponse
    {
        $this->authorize('delete', $purchase);

        $purchase->delete();
        $purchase->vehicle?->recalculateEfficiency();

        return ApiResponse::noContent();
    }

    /**
     * @OA\Get(path="/expenses/summary", tags={"Expenses"}, security={{"bearerAuth":{}}},
     *   summary="Spend, litres, km/L and cost/km for a period",
     *
     *   @OA\Response(response=200, description="Summary with monthly series and savings"))
     */
    public function summary(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        $scope = $this->scope($request)
            ->when($request->has('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')));

        return ApiResponse::success([
            'summary' => $this->expenses->summary(clone $scope, $from, $to),
            'monthly_series' => $this->expenses->monthlySeries(clone $scope, (int) $request->integer('months', 12)),
            'savings' => $this->expenses->savingsAnalysis(clone $scope, $from, $to),
        ]);
    }

    /** Restrict the query to what this caller may see. */
    /** @return Builder<FuelPurchase> */
    private function scope(Request $request): Builder
    {
        $user = $request->user();

        if ($user->isPlatformAdministrator()) {
            return FuelPurchase::query();
        }

        if ($user->company_id !== null) {
            return FuelPurchase::query()->whereIn(
                'vehicle_id',
                Vehicle::where('company_id', $user->company_id)->select('id'),
            );
        }

        return FuelPurchase::query()->where('user_id', $user->getKey());
    }
}
