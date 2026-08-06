<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Stations for a staging environment.
 *
 * Real brands on real roads, with coordinates close enough to the actual sites
 * that the map looks like the Philippines rather than a scatter plot. A tester
 * judging "is the nearest station sensible?" cannot do that against random
 * points, so the addresses are recognisable and the coordinates sit on the
 * streets they name.
 *
 * Idempotent: keyed on slug, so re-running adds nothing and overwrites nothing
 * a tester has edited.
 */
class GasStationSeeder extends Seeder
{
    /**
     * Brand, station name, address, city, latitude, longitude, 24h, EV.
     *
     * Coordinates are rounded to ~10m. They are close to the real sites but are
     * not survey data, and nothing here should be treated as authoritative for
     * anything but a demo.
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:float,5:float,6:bool,7:bool}>
     */
    private const STATIONS = [
        // --- Makati -------------------------------------------------------
        ['Petron', 'Petron Ayala Avenue', '6750 Ayala Ave, Bel-Air', 'Makati', 14.5561, 121.0245, true, false],
        ['Shell', 'Shell Chino Roces', '2286 Chino Roces Ave, Pio del Pilar', 'Makati', 14.5486, 121.0136, true, true],
        ['Caltex', 'Caltex Buendia', 'Sen. Gil Puyat Ave, Bel-Air', 'Makati', 14.5599, 121.0180, false, false],
        ['SEAOIL', 'SEAOIL Kalayaan', 'Kalayaan Ave cor. Malugay, Bel-Air', 'Makati', 14.5595, 121.0290, true, false],

        // --- Bonifacio Global City / Taguig -------------------------------
        ['Shell', 'Shell BGC 32nd Street', '32nd St cor. 5th Ave, BGC', 'Taguig', 14.5510, 121.0490, true, true],
        ['Petron', 'Petron McKinley', 'McKinley Pkwy, BGC', 'Taguig', 14.5378, 121.0490, true, false],
        ['Unioil', 'Unioil Lawton', 'Lawton Ave, Fort Bonifacio', 'Taguig', 14.5286, 121.0392, false, false],

        // --- Quezon City ---------------------------------------------------
        ['Petron', 'Petron Commonwealth', 'Commonwealth Ave, Batasan Hills', 'Quezon City', 14.6906, 121.0836, true, false],
        ['Shell', 'Shell Katipunan', 'Katipunan Ave, Loyola Heights', 'Quezon City', 14.6396, 121.0759, true, false],
        ['Caltex', 'Caltex Timog', 'Timog Ave, Sacred Heart', 'Quezon City', 14.6349, 121.0347, false, false],
        ['CleanFuel', 'CleanFuel Quezon Avenue', 'Quezon Ave, Paligsahan', 'Quezon City', 14.6357, 121.0136, true, false],
        ['Phoenix Petroleum', 'Phoenix Mindanao Avenue', 'Mindanao Ave, Talipapa', 'Quezon City', 14.6884, 121.0243, false, false],

        // --- Pasig / Mandaluyong -------------------------------------------
        ['Shell', 'Shell Ortigas Julia Vargas', 'Julia Vargas Ave, San Antonio', 'Pasig', 14.5828, 121.0614, true, true],
        ['Petron', 'Petron C5 Pasig', 'E. Rodriguez Jr. Ave (C5), Ugong', 'Pasig', 14.5866, 121.0781, true, false],
        ['Caltex', 'Caltex Shaw Boulevard', 'Shaw Blvd, Highway Hills', 'Mandaluyong', 14.5806, 121.0409, false, false],

        // --- Manila ---------------------------------------------------------
        ['Petron', 'Petron España', 'España Blvd, Sampaloc', 'Manila', 14.6091, 120.9930, true, false],
        ['Shell', 'Shell Roxas Boulevard', 'Roxas Blvd, Malate', 'Manila', 14.5648, 120.9878, true, false],
        ['Flying V', 'Flying V Taft Avenue', 'Taft Ave, Ermita', 'Manila', 14.5776, 120.9877, false, false],

        // --- Parañaque / Caloocan ------------------------------------------
        ['Total', 'TotalEnergies SLEX Sucat', 'Dr. A. Santos Ave, Sucat', 'Parañaque', 14.4720, 121.0263, true, false],
        ['Jetti', 'Jetti Coastal Road', 'Ninoy Aquino Ave, Sto. Niño', 'Parañaque', 14.4855, 121.0119, false, false],
        ['Petron', 'Petron EDSA Caloocan', 'EDSA, Grace Park', 'Caloocan', 14.6553, 120.9840, true, false],

        // --- Central Luzon ---------------------------------------------------
        ['Shell', 'Shell NLEX San Fernando', 'MacArthur Hwy, San Agustin', 'San Fernando', 15.0294, 120.6907, true, false],
        ['Petron', 'Petron Angeles Friendship', 'Friendship Hwy, Balibago', 'Angeles', 15.1663, 120.5921, true, false],
        ['SEAOIL', 'SEAOIL Malolos', 'MacArthur Hwy, Sumapang Matanda', 'Malolos', 14.8503, 120.8156, false, false],

        // --- CALABARZON -------------------------------------------------------
        ['Caltex', 'Caltex Dasmariñas', 'Governor\'s Dr, Salawag', 'Dasmariñas', 14.3268, 120.9405, false, false],
        ['Petron', 'Petron Aguinaldo Highway', 'Emilio Aguinaldo Hwy, Burol', 'Dasmariñas', 14.3350, 120.9391, true, false],
    ];

    public function run(): void
    {
        $brands = Brand::pluck('id', 'name');
        $cities = City::pluck('id', 'name');

        $created = 0;
        $skipped = 0;

        foreach (self::STATIONS as [$brand, $name, $address, $city, $lat, $lng, $open24, $ev]) {
            $brandId = $brands[$brand] ?? $brands->first(
                fn ($id, $brandName) => str_starts_with($brandName, $brand),
            );

            $cityId = $cities[$city] ?? null;

            // Reference data is a prerequisite, not something to invent here —
            // a station attached to a city that does not exist would break every
            // regional rollup downstream.
            if ($brandId === null || $cityId === null) {
                $this->command->warn("Skipping {$name}: unknown brand [{$brand}] or city [{$city}].");
                $skipped++;

                continue;
            }

            $station = GasStation::firstOrNew(['slug' => Str::slug($name)]);

            if ($station->exists) {
                $skipped++;

                continue;
            }

            $station->fill([
                'brand_id' => $brandId,
                'name' => $name,
                'address_line' => $address,
                'city_id' => $cityId,
                'latitude' => $lat,
                'longitude' => $lng,
                'is_24_hours' => $open24,
                'has_ev_charging' => $ev,
                'status' => 'active',
                'verified_at' => now(),
            ])->save();

            $created++;
        }

        $this->command->info("Stations: {$created} created, {$skipped} already present.");
    }
}
