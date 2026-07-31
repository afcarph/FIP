<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Haversine helpers used wherever a proximity calculation is needed outside of
 * MySQL (validation, fraud heuristics, route scoring).
 */
trait GeoDistance
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    public function distanceInMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function distanceInKilometres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->distanceInMetres($lat1, $lng1, $lat2, $lng2) / 1000;
    }

    /**
     * Bounding box for a radius search — used to let MySQL prune with the
     * B-tree index before the exact spatial predicate runs.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 110.574;
        $lngDelta = $radiusKm / (111.320 * max(cos(deg2rad($lat)), 0.000001));

        return [
            'min_lat' => $lat - $latDelta,
            'max_lat' => $lat + $latDelta,
            'min_lng' => $lng - $lngDelta,
            'max_lng' => $lng + $lngDelta,
        ];
    }
}
