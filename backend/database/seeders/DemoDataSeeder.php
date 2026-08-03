<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ai\Models\AiModel;
use App\Domain\Ai\Models\PriceForecast;
use App\Domain\Expense\Services\FuelExpenseService;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Maintenance\Services\MaintenanceService;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\MarketIndicator;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\Amenity;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use App\Domain\Station\Models\PaymentMethod;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use App\Domain\Vehicle\Models\VehicleMake;
use App\Domain\Vehicle\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A realistic demo dataset: one account per role, three companies, a working
 * fleet, twelve weeks of price history and a set of fill-ups that exercise
 * the efficiency and fraud logic.
 *
 * Data is generated through the real services (PriceService, FuelExpenseService)
 * rather than raw inserts, so the derived columns, history rows and anomaly
 * scores are produced by the same code paths that run in production.
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function __construct(
        private readonly PriceService $prices,
        private readonly FuelExpenseService $expenses,
        private readonly MaintenanceService $maintenance,
    ) {}

    public function run(): void
    {
        $companies = $this->seedCompanies();
        $users = $this->seedUsers($companies);
        $stations = $this->seedStations($companies['station']);
        $this->seedMarketData();
        $this->seedPrices($stations);
        $this->seedForecasts();
        $vehicles = $this->seedFleet($companies, $users);
        $this->seedFillUps($vehicles, $users, $stations);

        $this->command?->newLine();
        $this->command?->info('Demo data ready. Sign in with any of:');
        $this->command?->table(
            ['Role', 'Email', 'Password'],
            [
                ['Super Administrator', 'superadmin@fip.ph', self::PASSWORD],
                ['System Administrator', 'sysadmin@fip.ph', self::PASSWORD],
                ['Station Administrator', 'station@fip.ph', self::PASSWORD],
                ['Fleet Manager', 'fleet@fip.ph', self::PASSWORD],
                ['Company Manager', 'manager@fip.ph', self::PASSWORD],
                ['Driver', 'driver@fip.ph', self::PASSWORD],
                ['Registered User', 'user@fip.ph', self::PASSWORD],
            ],
        );
    }

    /** @return array<string, Company> */
    private function seedCompanies(): array
    {
        return [
            'logistics' => Company::updateOrCreate(['name' => 'Northstar Logistics'], [
                'legal_name' => 'Northstar Logistics Corp.',
                'tin' => '005-123-456-000',
                'type' => 'logistics',
                'address_line' => '12 Kalayaan Ave.',
                'city_id' => City::where('code', 'QZN')->value('id'),
                'contact_email' => 'ops@northstar.ph',
                'subscription_tier' => 'enterprise',
            ]),
            'trucking' => Company::updateOrCreate(['name' => 'MetroHaul Trucking'], [
                'legal_name' => 'MetroHaul Trucking Inc.',
                'type' => 'trucking',
                'address_line' => '88 C5 Service Rd.',
                'city_id' => City::where('code', 'TAG')->value('id'),
                'contact_email' => 'dispatch@metrohaul.ph',
                'subscription_tier' => 'business',
            ]),
            'station' => Company::updateOrCreate(['name' => 'Petron Alabang Group'], [
                'legal_name' => 'PAG Retailers Inc.',
                'type' => 'station_operator',
                'city_id' => City::where('code', 'MKT')->value('id'),
                'contact_email' => 'admin@pag.ph',
                'subscription_tier' => 'business',
            ]),
        ];
    }

    /** @return array<string, User> */
    private function seedUsers(array $companies): array
    {
        $definitions = [
            'super_admin' => ['Sofia', 'Ramos', 'superadmin@fip.ph', null, 'MKT'],
            'system_admin' => ['Miguel', 'Torres', 'sysadmin@fip.ph', null, 'QZN'],
            'station_admin' => ['Andrea', 'Lim', 'station@fip.ph', 'station', 'MKT'],
            'fleet_manager' => ['Rafael', 'Cruz', 'fleet@fip.ph', 'logistics', 'QZN'],
            'company_manager' => ['Bianca', 'Reyes', 'manager@fip.ph', 'logistics', 'QZN'],
            'driver' => ['Jomar', 'Dela Cruz', 'driver@fip.ph', 'logistics', 'PSG'],
            'user' => ['Ella', 'Santos', 'user@fip.ph', null, 'TAG'],
        ];

        $users = [];

        foreach ($definitions as $role => [$firstName, $lastName, $email, $companyKey, $cityCode]) {
            $user = User::updateOrCreate(['email' => $email], [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => self::PASSWORD,
                'company_id' => $companyKey !== null ? $companies[$companyKey]->getKey() : null,
                'home_city_id' => City::where('code', $cityCode)->value('id'),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $user->syncRoles([$role]);

            UserPreference::updateOrCreate(['user_id' => $user->getKey()], [
                'theme' => 'system',
                'preferred_fuel_type_id' => FuelType::where('code', $role === 'fleet_manager' ? 'diesel' : 'gasoline_ron95')->value('id'),
                'alert_radius_km' => 5,
            ]);

            $users[$role] = $user;
        }

        return $users;
    }

    /** @return Collection<int, GasStation> */
    private function seedStations(Company $operator)
    {
        $definitions = [
            ['petron', 'Petron Ayala Avenue', '6750 Ayala Ave., Makati', 'MKT', 14.5570, 121.0230, true, true],
            ['shell', 'Shell EDSA Guadalupe', 'EDSA cor. Guadalupe, Makati', 'MKT', 14.5665, 121.0450, true, false],
            ['caltex', 'Caltex Commonwealth', 'Commonwealth Ave., Quezon City', 'QZN', 14.6800, 121.0700, true, false],
            ['seaoil', 'SEAOIL BGC 32nd Street', '32nd St., BGC, Taguig', 'TAG', 14.5510, 121.0490, false, true],
            ['phoenix', 'Phoenix Ortigas Extension', 'Ortigas Ext., Pasig', 'PSG', 14.5800, 121.0900, true, false],
            ['unioil', 'Unioil España', 'España Blvd., Manila', 'MNA', 14.6100, 120.9930, false, false],
            ['cleanfuel', 'CleanFuel Dasmariñas', 'Aguinaldo Hwy., Dasmariñas', 'DAS', 14.3300, 120.9370, false, false],
            ['shell', 'Shell Cebu Business Park', 'Cardinal Rosales Ave., Cebu City', 'CEB', 10.3180, 123.9050, true, true],
            ['petron', 'Petron Davao Ecoland', 'Quimpo Blvd., Davao City', 'DVO', 7.0700, 125.6100, true, false],
            ['total', 'TotalEnergies Santa Rosa', 'Old National Hwy., Santa Rosa', 'STR', 14.3120, 121.1120, false, false],
            ['petron', 'Petron NLEX Balintawak', 'NLEX, Caloocan', 'CAL', 14.6580, 120.9880, true, false],
            ['shell', 'Shell SLEX Sucat', 'SLEX Sucat, Parañaque', 'PRN', 14.4790, 121.0200, true, true],
        ];

        $amenityIds = Amenity::pluck('id')->all();
        $paymentIds = PaymentMethod::pluck('id')->all();
        $stations = collect();

        foreach ($definitions as $index => [$brandCode, $name, $address, $cityCode, $lat, $lng, $is24h, $hasEv]) {
            $station = GasStation::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'brand_id' => Brand::where('code', $brandCode)->value('id'),
                    'operator_id' => $index === 0 ? $operator->getKey() : null,
                    'managed_by' => $index === 0 ? User::where('email', 'station@fip.ph')->value('id') : null,
                    'name' => $name,
                    'address_line' => $address,
                    'city_id' => City::where('code', $cityCode)->value('id'),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'is_24_hours' => $is24h,
                    'has_ev_charging' => $hasEv,
                    'status' => 'active',
                    'verified_at' => now(),
                ],
            );

            // Give each station a plausible, varied amenity/payment mix.
            $station->amenities()->sync(collect($amenityIds)->random(random_int(3, 6))->all());
            $station->paymentMethods()->sync(collect($paymentIds)->random(random_int(3, 5))->all());

            $stations->push($station);
        }

        return $stations;
    }

    private function seedMarketData(): void
    {
        $series = [
            'dubai_crude' => [78.20, 79.90, 81.10, 80.40, 82.35, 79.10, 78.42, 80.05],
            'mops_gasoline' => [88.40, 90.10, 91.60, 90.80, 92.10, 88.60, 87.95, 89.80],
            'usd_php' => [57.10, 57.35, 57.60, 57.72, 57.85, 57.42, 57.60, 58.15],
        ];

        foreach ($series as $indicator => $values) {
            foreach ($values as $offset => $value) {
                MarketIndicator::updateOrCreate(
                    [
                        'indicator' => $indicator,
                        'observed_on' => now()->subWeeks(count($values) - $offset)->startOfWeek()->toDateString(),
                    ],
                    [
                        'value' => $value,
                        'unit' => $indicator === 'usd_php' ? 'PHP' : 'USD/bbl',
                        'source' => $indicator === 'usd_php' ? 'BSP' : 'Platts',
                    ],
                );
            }
        }
    }

    /** @param Collection<int, GasStation> $stations */
    private function seedPrices($stations): void
    {
        $fuelTypes = FuelType::whereIn('code', ['gasoline_ron91', 'gasoline_ron95', 'diesel'])->get();

        // Base prices roughly reflect the mid-2026 Metro Manila range.
        $basePrices = ['gasoline_ron91' => 59.20, 'gasoline_ron95' => 63.10, 'diesel' => 57.60];

        // Twelve weeks of movement so the trend chart and the model have data.
        $weeklyDeltas = [0.80, -0.50, 0.35, 1.20, -0.90, 0.00, -0.45, 0.60, 0.25, -1.10, -0.30, 0.40];

        foreach ($fuelTypes as $fuelType) {
            $price = $basePrices[$fuelType->code];

            foreach ($weeklyDeltas as $week => $delta) {
                $weekStart = now()->subWeeks(count($weeklyDeltas) - $week)->startOfWeek();
                $price = round($price + $delta, 4);

                PriceAdvisory::updateOrCreate(
                    ['fuel_type_id' => $fuelType->getKey(), 'region_id' => null, 'week_start' => $weekStart->toDateString()],
                    [
                        'effective_at' => $weekStart->copy()->addDay()->setTime(6, 0),
                        'change_amount' => $delta,
                        'direction' => match (true) {
                            $delta > 0.001 => 'increase',
                            $delta < -0.001 => 'rollback',
                            default => 'no_change',
                        },
                        'source' => 'doe',
                    ],
                );

                foreach ($stations as $station) {
                    // Each station deviates slightly from the national average.
                    $stationPrice = round($price + $this->stationOffset($station), 4);

                    $this->prices->recordPrice(
                        station: $station,
                        fuelTypeId: $fuelType->getKey(),
                        price: $stationPrice,
                        source: $week === count($weeklyDeltas) - 1 ? 'operator' : 'doe',
                        confidence: 1.0,
                        effectiveAt: $weekStart->copy()->addDay()->setTime(6, 0),
                    );
                }
            }
        }
    }

    /** Deterministic per-station price offset in the ±₱1.20 band. */
    private function stationOffset(GasStation $station): float
    {
        return round(((crc32((string) $station->slug) % 241) - 120) / 100, 2);
    }

    private function seedForecasts(): void
    {
        $model = AiModel::updateOrCreate(
            ['code' => 'price_forecast', 'version' => '1.4.0'],
            [
                'name' => 'Weekly Pump Price Forecaster',
                'algorithm' => 'prophet+xgboost',
                'metrics' => ['mae' => 0.214, 'rmse' => 0.312, 'mape' => 0.0041, 'direction_accuracy' => 0.842],
                'trained_at' => now()->subDays(4),
                'training_rows' => 5480,
                'is_active' => true,
            ],
        );

        foreach ([
            ['gasoline_ron95', 'increase', 0.55, 0.812, 'Regional gasoline cracks widened for a second week while the peso weakened. A ₱0.45–₱0.65 per-litre increase is likely on Tuesday.'],
            ['diesel', 'increase', 0.40, 0.774, 'Diesel follows crude higher but a softer gasoil crack caps the move at roughly ₱0.40 per litre.'],
            ['gasoline_ron91', 'increase', 0.50, 0.786, 'RON 91 tracks RON 95 with a marginally smaller adjustment.'],
        ] as [$fuelCode, $direction, $change, $confidence, $narrative]) {
            $fuelType = FuelType::where('code', $fuelCode)->first();

            if ($fuelType === null) {
                continue;
            }

            PriceForecast::updateOrCreate(
                [
                    'fuel_type_id' => $fuelType->getKey(),
                    'region_id' => null,
                    'forecast_for' => now()->startOfWeek()->addWeek()->toDateString(),
                    'generated_at' => now()->startOfDay(),
                ],
                [
                    'ai_model_id' => $model->getKey(),
                    'direction' => $direction,
                    'change_amount' => $change,
                    'confidence' => $confidence,
                    'narrative' => $narrative,
                    'drivers' => [
                        ['factor' => 'MOPS regional crack', 'weight' => 0.46, 'value' => '+2.1%'],
                        ['factor' => 'Dubai crude', 'weight' => 0.28, 'value' => '+2.1 USD/bbl'],
                        ['factor' => 'USD/PHP', 'weight' => 0.18, 'value' => '58.15'],
                        ['factor' => 'Seasonality', 'weight' => 0.08, 'value' => 'neutral'],
                    ],
                ],
            );
        }
    }

    /** @return Collection<int, Vehicle> */
    private function seedFleet(array $companies, array $users)
    {
        $fleet = Fleet::updateOrCreate(
            ['company_id' => $companies['logistics']->getKey(), 'code' => 'NS-MM'],
            [
                'name' => 'Metro Manila Delivery',
                'manager_id' => $users['fleet_manager']->getKey(),
                'base_city_id' => City::where('code', 'QZN')->value('id'),
                'monthly_fuel_budget' => 850000,
            ],
        );

        $driver = Driver::updateOrCreate(
            ['company_id' => $companies['logistics']->getKey(), 'employee_no' => 'NS-0001'],
            [
                'user_id' => $users['driver']->getKey(),
                'fleet_id' => $fleet->getKey(),
                'first_name' => 'Jomar',
                'last_name' => 'Dela Cruz',
                'licence_number' => 'N02-11-123456',
                'licence_type' => 'Professional',
                'licence_expiry' => now()->addMonths(8)->toDateString(),
                'hired_at' => now()->subYears(3)->toDateString(),
                'status' => 'active',
            ],
        );

        $definitions = [
            // owner-held vehicles
            ['user', null, null, 'Toyota', 'Vios', 'gasoline_ron91', 'ABC 1234', 'car', 42, 48250, 14.5],
            ['user', null, null, 'Yamaha', 'NMAX 155', 'gasoline_ron91', 'MC 5678', 'motorcycle', 7.1, 12400, 45.0],
            // company vehicles
            [null, 'logistics', 'NS-MM', 'Mitsubishi', 'L300', 'diesel', 'NS 1001', 'van', 60, 182300, 9.8],
            [null, 'logistics', 'NS-MM', 'Isuzu', 'N-Series NLR', 'diesel', 'NS 1002', 'truck', 100, 264100, 6.5],
            [null, 'trucking', null, 'Toyota', 'Hilux', 'diesel', 'MH 3001', 'truck', 80, 96700, 10.2],
        ];

        $vehicles = collect();

        foreach ($definitions as [$ownerKey, $companyKey, $fleetCode, $makeName, $modelName, $fuelCode, $plate, $type, $tank, $odometer, $baseline]) {
            $make = VehicleMake::where('name', $makeName)->first();

            $vehicle = Vehicle::updateOrCreate(['plate_number' => $plate], [
                'owner_id' => $ownerKey !== null ? $users[$ownerKey]->getKey() : null,
                'company_id' => $companyKey !== null ? $companies[$companyKey]->getKey() : null,
                'fleet_id' => $fleetCode !== null ? $fleet->getKey() : null,
                'make_id' => $make?->getKey(),
                'model_id' => VehicleModel::where('make_id', $make?->getKey())->where('name', $modelName)->value('id'),
                'fuel_type_id' => FuelType::where('code', $fuelCode)->value('id'),
                'vehicle_type' => $type,
                'year' => 2022,
                'tank_capacity' => $tank,
                'current_odometer' => $odometer,
                'baseline_km_per_litre' => $baseline,
                'registration_expiry' => now()->addMonths(random_int(1, 10))->toDateString(),
                'insurance_provider' => 'Malayan Insurance',
                'insurance_expiry' => now()->addMonths(random_int(1, 11))->toDateString(),
                'status' => 'active',
            ]);

            $this->maintenance->bootstrapSchedule($vehicle);
            $vehicles->push($vehicle);
        }

        // Give the driver a live assignment on the delivery van.
        $van = $vehicles->firstWhere('plate_number', 'NS 1001');

        if ($van !== null) {
            VehicleAssignment::updateOrCreate(
                ['vehicle_id' => $van->getKey(), 'driver_id' => $driver->getKey(), 'released_at' => null],
                ['assigned_at' => now()->subYear(), 'assigned_by' => $users['fleet_manager']->getKey()],
            );
        }

        return $vehicles;
    }

    /**
     * Six months of fill-ups per vehicle, generated through the real expense
     * service. One vehicle is given a deliberately anomalous last fill so the
     * fraud dashboard has something to show.
     */
    private function seedFillUps($vehicles, array $users, $stations): void
    {
        foreach ($vehicles as $vehicle) {
            $actor = $vehicle->owner_id !== null
                ? $users['user']
                : ($vehicle->company_id === $users['fleet_manager']->company_id ? $users['fleet_manager'] : $users['company_manager']);

            $odometer = max($vehicle->current_odometer - 6000, 0);
            $intervalDays = $vehicle->vehicle_type === 'motorcycle' ? 14 : 10;

            for ($i = 12; $i >= 1; $i--) {
                $purchasedAt = now()->subDays($i * $intervalDays)->setTime(random_int(6, 19), random_int(0, 59));
                $station = $stations->random();
                $price = $station->priceFor((int) $vehicle->fuel_type_id);

                if ($price === null) {
                    continue;
                }

                // Distance drifts around the baseline to give km/L some texture.
                $distance = round(($vehicle->baseline_km_per_litre ?? 10) * ($vehicle->tank_capacity * 0.75) * (0.9 + (random_int(0, 20) / 100)), 2);
                $odometer = round($odometer + $distance, 2);
                $litres = round($distance / max($vehicle->baseline_km_per_litre ?? 10, 1), 3);

                // Last fill on the delivery van is implausibly efficient — an
                // odometer gap without the fuel to match, which is the classic
                // ghost-refuel signature the detector should catch.
                if ($i === 1 && $vehicle->plate_number === 'NS 1001') {
                    $odometer = round($odometer + $distance * 0.8, 2);
                }

                $this->expenses->record($actor, $vehicle, [
                    'station_id' => $station->getKey(),
                    'fuel_type_id' => $vehicle->fuel_type_id,
                    'litres' => $litres,
                    'price_per_litre' => (float) $price->price,
                    'total_cost' => round($litres * (float) $price->price, 2),
                    'odometer' => $odometer,
                    'is_full_tank' => true,
                    'purchased_at' => $purchasedAt->toDateTimeString(),
                    'latitude' => $station->latitude,
                    'longitude' => $station->longitude,
                ]);
            }

            $vehicle->recalculateEfficiency();
        }
    }
}
