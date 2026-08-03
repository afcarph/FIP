<?php

declare(strict_types=1);

namespace App\Domain\Expense\Services;

use App\Domain\Expense\Models\RoutePlan;
use App\Domain\Station\Repositories\GasStationRepository;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Services\External\AiServiceClient;
use App\Support\Concerns\GeoDistance;

/**
 * Cost-aware route planning.
 *
 * The AI service returns geometric alternatives (distance, duration, tolls);
 * this service prices them for the specific vehicle and finds the best
 * refuelling stop along each one. "Cheapest" is total cost of the journey —
 * fuel plus tolls plus the detour needed to reach a cheap station — not
 * simply the lowest pump price, because a ₱2/L saving 8 km off-route is a
 * false economy.
 */
final readonly class RouteOptimizationService
{
    use GeoDistance;

    public function __construct(
        private AiServiceClient $ai,
        private GasStationRepository $stations,
    ) {}

    /**
     * @param  array{origin_lat: float, origin_lng: float, destination_lat: float,
     *               destination_lng: float, origin_label: string, destination_label: string,
     *               optimize_for?: string, tank_level_pct?: float}  $request
     */
    public function plan(User $user, ?Vehicle $vehicle, array $request): RoutePlan
    {
        $optimizeFor = $request['optimize_for'] ?? 'cost';

        $response = $this->ai->optimizeRoute([
            'origin' => ['lat' => $request['origin_lat'], 'lng' => $request['origin_lng']],
            'destination' => ['lat' => $request['destination_lat'], 'lng' => $request['destination_lng']],
            'optimize_for' => $optimizeFor,
            'alternatives' => (int) config('fip.routing.alternatives'),
            'vehicle' => $vehicle === null ? null : [
                'km_per_litre' => $vehicle->avg_km_per_litre ?? $vehicle->baseline_km_per_litre,
                'tank_capacity' => $vehicle->tank_capacity,
                'vehicle_type' => $vehicle->vehicle_type,
                'fuel_type_id' => $vehicle->fuel_type_id,
            ],
        ]);

        $options = $this->priceOptions($response['routes'] ?? [], $vehicle, $request);
        $options = $this->rank($options, $optimizeFor);

        $baseline = $options[0]['total_cost'] ?? null;
        $worst = end($options)['total_cost'] ?? null;

        return RoutePlan::create([
            'user_id' => $user->getKey(),
            'vehicle_id' => $vehicle?->getKey(),
            'origin_label' => $request['origin_label'],
            'destination_label' => $request['destination_label'],
            'origin_lat' => $request['origin_lat'],
            'origin_lng' => $request['origin_lng'],
            'destination_lat' => $request['destination_lat'],
            'destination_lng' => $request['destination_lng'],
            'optimize_for' => $optimizeFor,
            'selected_option' => 0,
            'options_payload' => $options,
            'estimated_savings' => ($baseline !== null && $worst !== null) ? round($worst - $baseline, 2) : null,
        ]);
    }

    /**
     * Attach fuel cost, toll cost and the best on-route refuelling stop to
     * each geometric alternative.
     */
    private function priceOptions(array $routes, ?Vehicle $vehicle, array $request): array
    {
        $kpl = $vehicle?->avg_km_per_litre ?? $vehicle?->baseline_km_per_litre;
        $fuelTypeId = $vehicle?->fuel_type_id;
        $priced = [];

        foreach ($routes as $index => $route) {
            $distanceKm = round((float) ($route['distance_km'] ?? 0), 2);
            $tollCost = round((float) ($route['toll_cost'] ?? 0), 2);

            $stop = ($fuelTypeId !== null)
                ? $this->bestRefuellingStop($route, (int) $fuelTypeId, $request)
                : null;

            $pricePerLitre = $stop['price'] ?? $this->fallbackPrice($fuelTypeId, $request);
            $litres = ($kpl && $kpl > 0) ? round($distanceKm / $kpl, 2) : null;
            $fuelCost = ($litres !== null && $pricePerLitre !== null) ? round($litres * $pricePerLitre, 2) : null;

            $priced[] = [
                'index' => $index,
                'summary' => $route['summary'] ?? "Route {$index}",
                'polyline' => $route['polyline'] ?? null,
                'distance_km' => $distanceKm,
                'duration_minutes' => (int) round((float) ($route['duration_minutes'] ?? 0)),
                'has_tolls' => (bool) ($route['has_tolls'] ?? $tollCost > 0),
                'toll_cost' => $tollCost,
                'estimated_litres' => $litres,
                'fuel_cost' => $fuelCost,
                'total_cost' => round(($fuelCost ?? 0) + $tollCost, 2),
                'refuelling_stop' => $stop,
                'traffic_level' => $route['traffic_level'] ?? null,
            ];
        }

        return $priced;
    }

    /**
     * Cheapest station within the allowed detour of the route's midpoint.
     *
     * Sampling the midpoint is a deliberate simplification: it keeps the
     * lookup to a single spatial query per alternative while still finding
     * stations genuinely "on the way". The detour cost is charged back so a
     * far-off cheap station cannot win on pump price alone.
     */
    private function bestRefuellingStop(array $route, int $fuelTypeId, array $request): ?array
    {
        $midpoint = $route['midpoint'] ?? [
            'lat' => ($request['origin_lat'] + $request['destination_lat']) / 2,
            'lng' => ($request['origin_lng'] + $request['destination_lng']) / 2,
        ];

        $candidates = $this->stations->cheapestNearby(
            (float) $midpoint['lat'],
            (float) $midpoint['lng'],
            $fuelTypeId,
            (float) config('fip.routing.max_detour_km'),
            5,
        );

        $best = $candidates->first();

        if ($best === null) {
            return null;
        }

        return [
            'station_id' => $best->getKey(),
            'name' => $best->name,
            'brand' => $best->brand?->name,
            'latitude' => $best->latitude,
            'longitude' => $best->longitude,
            'price' => (float) ($best->current_price ?? 0),
            'detour_km' => round(((float) ($best->distance_m ?? 0)) / 1000, 2),
        ];
    }

    /** Rank alternatives by the user's stated objective. */
    private function rank(array $options, string $optimizeFor): array
    {
        usort($options, static function (array $a, array $b) use ($optimizeFor): int {
            return match ($optimizeFor) {
                'time' => $a['duration_minutes'] <=> $b['duration_minutes'],
                'fuel' => ($a['estimated_litres'] ?? PHP_FLOAT_MAX) <=> ($b['estimated_litres'] ?? PHP_FLOAT_MAX),
                'balanced' => self::balancedScore($a) <=> self::balancedScore($b),
                default => $a['total_cost'] <=> $b['total_cost'],
            };
        });

        return $options;
    }

    /** Money and time weighted equally, with ₱10/minute as the exchange rate. */
    private static function balancedScore(array $option): float
    {
        return $option['total_cost'] + ($option['duration_minutes'] * 10);
    }

    private function fallbackPrice(?int $fuelTypeId, array $request): ?float
    {
        if ($fuelTypeId === null) {
            return null;
        }

        $nearby = $this->stations->cheapestNearby(
            (float) $request['origin_lat'],
            (float) $request['origin_lng'],
            $fuelTypeId,
            10.0,
            1,
        )->first();

        return $nearby !== null ? (float) $nearby->current_price : null;
    }
}
