<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Maintenance\Models\MaintenanceType;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Station\Models\Amenity;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\PaymentMethod;
use App\Domain\Station\Models\Province;
use App\Domain\Station\Models\Region;
use App\Domain\Vehicle\Models\VehicleMake;
use App\Domain\Vehicle\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * Immutable-ish reference data: geography, fuel types, brands, amenities,
 * service items, notification copy and report definitions.
 *
 * Idempotent — safe to re-run on every deploy so new reference rows roll out
 * without a bespoke migration.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGeography();
        $this->seedFuelTypes();
        $this->seedBrands();
        $this->seedAmenitiesAndPayments();
        $this->seedVehicleCatalogue();
        $this->seedMaintenanceTypes();
        $this->seedNotificationTemplates();
        $this->seedReportDefinitions();
    }

    private function seedGeography(): void
    {
        $regions = [
            ['code' => 'NCR', 'name' => 'National Capital Region', 'island_group' => 'luzon'],
            ['code' => 'R1', 'name' => 'Ilocos Region', 'island_group' => 'luzon'],
            ['code' => 'R3', 'name' => 'Central Luzon', 'island_group' => 'luzon'],
            ['code' => 'R4A', 'name' => 'CALABARZON', 'island_group' => 'luzon'],
            ['code' => 'R6', 'name' => 'Western Visayas', 'island_group' => 'visayas'],
            ['code' => 'R7', 'name' => 'Central Visayas', 'island_group' => 'visayas'],
            ['code' => 'R10', 'name' => 'Northern Mindanao', 'island_group' => 'mindanao'],
            ['code' => 'R11', 'name' => 'Davao Region', 'island_group' => 'mindanao'],
        ];

        foreach ($regions as $region) {
            Region::updateOrCreate(['code' => $region['code']], $region);
        }

        $provinces = [
            ['code' => 'MNL', 'name' => 'Metro Manila', 'region' => 'NCR'],
            ['code' => 'PAM', 'name' => 'Pampanga', 'region' => 'R3'],
            ['code' => 'BUL', 'name' => 'Bulacan', 'region' => 'R3'],
            ['code' => 'CAV', 'name' => 'Cavite', 'region' => 'R4A'],
            ['code' => 'LAG', 'name' => 'Laguna', 'region' => 'R4A'],
            ['code' => 'BTG', 'name' => 'Batangas', 'region' => 'R4A'],
            ['code' => 'CEB', 'name' => 'Cebu', 'region' => 'R7'],
            ['code' => 'ILO', 'name' => 'Iloilo', 'region' => 'R6'],
            ['code' => 'MSR', 'name' => 'Misamis Oriental', 'region' => 'R10'],
            ['code' => 'DVO', 'name' => 'Davao del Sur', 'region' => 'R11'],
        ];

        foreach ($provinces as $province) {
            Province::updateOrCreate(
                ['code' => $province['code']],
                [
                    'name' => $province['name'],
                    'region_id' => Region::where('code', $province['region'])->value('id'),
                ],
            );
        }

        $cities = [
            ['code' => 'MKT', 'name' => 'Makati', 'province' => 'MNL', 'lat' => 14.5547, 'lng' => 121.0244],
            ['code' => 'QZN', 'name' => 'Quezon City', 'province' => 'MNL', 'lat' => 14.6760, 'lng' => 121.0437],
            ['code' => 'TAG', 'name' => 'Taguig', 'province' => 'MNL', 'lat' => 14.5176, 'lng' => 121.0509],
            ['code' => 'PSG', 'name' => 'Pasig', 'province' => 'MNL', 'lat' => 14.5764, 'lng' => 121.0851],
            ['code' => 'MNA', 'name' => 'Manila', 'province' => 'MNL', 'lat' => 14.5995, 'lng' => 120.9842],
            ['code' => 'MDL', 'name' => 'Mandaluyong', 'province' => 'MNL', 'lat' => 14.5794, 'lng' => 121.0359],
            ['code' => 'PRN', 'name' => 'Parañaque', 'province' => 'MNL', 'lat' => 14.4793, 'lng' => 121.0198],
            ['code' => 'CAL', 'name' => 'Caloocan', 'province' => 'MNL', 'lat' => 14.6507, 'lng' => 120.9676],
            ['code' => 'SFP', 'name' => 'San Fernando', 'province' => 'PAM', 'lat' => 15.0342, 'lng' => 120.6839],
            ['code' => 'ANG', 'name' => 'Angeles', 'province' => 'PAM', 'lat' => 15.1450, 'lng' => 120.5887],
            ['code' => 'MAL', 'name' => 'Malolos', 'province' => 'BUL', 'lat' => 14.8433, 'lng' => 120.8114],
            ['code' => 'DAS', 'name' => 'Dasmariñas', 'province' => 'CAV', 'lat' => 14.3294, 'lng' => 120.9367],
            ['code' => 'BCR', 'name' => 'Bacoor', 'province' => 'CAV', 'lat' => 14.4590, 'lng' => 120.9410],
            ['code' => 'STR', 'name' => 'Santa Rosa', 'province' => 'LAG', 'lat' => 14.3122, 'lng' => 121.1114],
            ['code' => 'CLB', 'name' => 'Calamba', 'province' => 'LAG', 'lat' => 14.2117, 'lng' => 121.1653],
            ['code' => 'LIP', 'name' => 'Lipa', 'province' => 'BTG', 'lat' => 13.9411, 'lng' => 121.1622],
            ['code' => 'CEB', 'name' => 'Cebu City', 'province' => 'CEB', 'lat' => 10.3157, 'lng' => 123.8854],
            ['code' => 'MDU', 'name' => 'Mandaue', 'province' => 'CEB', 'lat' => 10.3231, 'lng' => 123.9224],
            ['code' => 'ILO', 'name' => 'Iloilo City', 'province' => 'ILO', 'lat' => 10.7202, 'lng' => 122.5621],
            ['code' => 'CDO', 'name' => 'Cagayan de Oro', 'province' => 'MSR', 'lat' => 8.4542, 'lng' => 124.6319],
            ['code' => 'DVO', 'name' => 'Davao City', 'province' => 'DVO', 'lat' => 7.1907, 'lng' => 125.4553],
        ];

        foreach ($cities as $city) {
            City::updateOrCreate(
                ['code' => $city['code']],
                [
                    'name' => $city['name'],
                    'province_id' => Province::where('code', $city['province'])->value('id'),
                    'is_city' => true,
                    'latitude' => $city['lat'],
                    'longitude' => $city['lng'],
                ],
            );
        }
    }

    private function seedFuelTypes(): void
    {
        $types = [
            ['code' => 'gasoline_ron91', 'name' => 'Gasoline RON 91', 'category' => 'gasoline', 'octane' => 91, 'color_hex' => '#22C55E', 'sort_order' => 1],
            ['code' => 'gasoline_ron95', 'name' => 'Gasoline RON 95', 'category' => 'gasoline', 'octane' => 95, 'color_hex' => '#16A34A', 'sort_order' => 2],
            ['code' => 'gasoline_ron97', 'name' => 'Gasoline RON 97', 'category' => 'gasoline', 'octane' => 97, 'color_hex' => '#15803D', 'sort_order' => 3],
            ['code' => 'diesel', 'name' => 'Diesel', 'category' => 'diesel', 'octane' => null, 'color_hex' => '#F59E0B', 'sort_order' => 4],
            ['code' => 'diesel_premium', 'name' => 'Premium Diesel', 'category' => 'diesel', 'octane' => null, 'color_hex' => '#D97706', 'sort_order' => 5],
            ['code' => 'kerosene', 'name' => 'Kerosene', 'category' => 'diesel', 'octane' => null, 'color_hex' => '#8B5CF6', 'sort_order' => 6],
            ['code' => 'lpg_auto', 'name' => 'Auto LPG', 'category' => 'lpg', 'octane' => null, 'color_hex' => '#EC4899', 'sort_order' => 7],
            ['code' => 'ev_dc_fast', 'name' => 'EV DC Fast Charge', 'category' => 'ev', 'octane' => null, 'color_hex' => '#0EA5E9', 'sort_order' => 8],
        ];

        foreach ($types as $type) {
            FuelType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['unit' => $type['category'] === 'ev' ? 'kWh' : 'L', 'is_active' => true],
            );
        }
    }

    private function seedBrands(): void
    {
        $brands = [
            ['code' => 'petron', 'name' => 'Petron', 'color_hex' => '#0033A0'],
            ['code' => 'shell', 'name' => 'Shell', 'color_hex' => '#DD1D21'],
            ['code' => 'caltex', 'name' => 'Caltex', 'color_hex' => '#E31837'],
            ['code' => 'phoenix', 'name' => 'Phoenix Petroleum', 'color_hex' => '#F57C00'],
            ['code' => 'seaoil', 'name' => 'SEAOIL', 'color_hex' => '#003DA5'],
            ['code' => 'cleanfuel', 'name' => 'CleanFuel', 'color_hex' => '#00A651'],
            ['code' => 'unioil', 'name' => 'Unioil', 'color_hex' => '#E4002B'],
            ['code' => 'total', 'name' => 'TotalEnergies', 'color_hex' => '#ED0000'],
            ['code' => 'jetti', 'name' => 'Jetti', 'color_hex' => '#F7941D'],
            ['code' => 'flying_v', 'name' => 'Flying V', 'color_hex' => '#005DAA'],
        ];

        foreach ($brands as $brand) {
            Brand::updateOrCreate(['code' => $brand['code']], $brand + ['is_active' => true]);
        }
    }

    private function seedAmenitiesAndPayments(): void
    {
        $amenities = [
            ['code' => 'convenience_store', 'name' => 'Convenience Store', 'icon' => 'store'],
            ['code' => 'restroom', 'name' => 'Restroom', 'icon' => 'toilet'],
            ['code' => 'atm', 'name' => 'ATM', 'icon' => 'banknote'],
            ['code' => 'car_wash', 'name' => 'Car Wash', 'icon' => 'droplets'],
            ['code' => 'air_water', 'name' => 'Air & Water', 'icon' => 'wind'],
            ['code' => 'lubes_bay', 'name' => 'Lube Servicing Bay', 'icon' => 'wrench'],
            ['code' => 'food', 'name' => 'Food Court', 'icon' => 'utensils'],
            ['code' => 'ev_charging', 'name' => 'EV Charging', 'icon' => 'zap'],
            ['code' => 'truck_lane', 'name' => 'Truck Lane', 'icon' => 'truck'],
            ['code' => 'wifi', 'name' => 'Free Wi-Fi', 'icon' => 'wifi'],
        ];

        foreach ($amenities as $amenity) {
            Amenity::updateOrCreate(['code' => $amenity['code']], $amenity);
        }

        $methods = [
            ['code' => 'cash', 'name' => 'Cash', 'icon' => 'banknote'],
            ['code' => 'credit_card', 'name' => 'Credit Card', 'icon' => 'credit-card'],
            ['code' => 'debit_card', 'name' => 'Debit Card', 'icon' => 'credit-card'],
            ['code' => 'gcash', 'name' => 'GCash', 'icon' => 'smartphone'],
            ['code' => 'maya', 'name' => 'Maya', 'icon' => 'smartphone'],
            ['code' => 'fleet_card', 'name' => 'Fleet Card', 'icon' => 'id-card'],
            ['code' => 'qr_ph', 'name' => 'QR Ph', 'icon' => 'qr-code'],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(['code' => $method['code']], $method);
        }
    }

    private function seedVehicleCatalogue(): void
    {
        $catalogue = [
            'Toyota' => [
                ['Hilux', 'pickup', 'diesel', 80],
                ['Vios', 'sedan', 'gasoline_ron91', 42],
                ['Fortuner', 'suv', 'diesel', 80],
                ['Hiace', 'van', 'diesel', 70],
            ],
            'Mitsubishi' => [
                ['L300', 'van', 'diesel', 60],
                ['Montero Sport', 'suv', 'diesel', 68],
                ['Mirage', 'hatchback', 'gasoline_ron91', 35],
            ],
            'Isuzu' => [
                ['N-Series NLR', 'truck', 'diesel', 100],
                ['D-Max', 'pickup', 'diesel', 76],
                ['mu-X', 'suv', 'diesel', 65],
            ],
            'Honda' => [
                ['Civic', 'sedan', 'gasoline_ron95', 47],
                ['City', 'sedan', 'gasoline_ron91', 40],
                ['CR-V', 'suv', 'gasoline_ron95', 57],
            ],
            'Hino' => [
                ['500 Series', 'truck', 'diesel', 200],
                ['300 Series', 'truck', 'diesel', 100],
            ],
            'Yamaha' => [
                ['NMAX 155', 'motorcycle', 'gasoline_ron91', 7.1],
                ['Mio i 125', 'motorcycle', 'gasoline_ron91', 4.2],
            ],
            'Honda Motorcycles' => [
                ['Click 125i', 'motorcycle', 'gasoline_ron91', 5.5],
                ['TMX 125', 'motorcycle', 'gasoline_ron91', 10.0],
            ],
        ];

        foreach ($catalogue as $makeName => $models) {
            $make = VehicleMake::updateOrCreate(['name' => $makeName]);

            foreach ($models as [$name, $bodyType, $fuelCode, $tank]) {
                VehicleModel::updateOrCreate(
                    ['make_id' => $make->getKey(), 'name' => $name],
                    [
                        'body_type' => $bodyType,
                        'default_fuel_type_id' => FuelType::where('code', $fuelCode)->value('id'),
                        'tank_capacity' => $tank,
                    ],
                );
            }
        }
    }

    private function seedMaintenanceTypes(): void
    {
        $types = [
            ['oil_change', 'Engine Oil Change', 'preventive', 5000, 180, 'droplet'],
            ['oil_filter', 'Oil Filter Replacement', 'preventive', 10000, 365, 'filter'],
            ['air_filter', 'Air Filter Replacement', 'preventive', 15000, 365, 'wind'],
            ['fuel_filter', 'Fuel Filter Replacement', 'preventive', 40000, 730, 'filter'],
            ['tire_rotation', 'Tire Rotation', 'preventive', 10000, 180, 'circle-dot'],
            ['tire_replacement', 'Tire Replacement', 'corrective', 50000, 1460, 'circle'],
            ['brake_pads', 'Brake Pad Replacement', 'corrective', 40000, 730, 'disc'],
            ['battery', 'Battery Replacement', 'corrective', null, 900, 'battery'],
            ['coolant', 'Coolant Flush', 'preventive', 40000, 730, 'thermometer'],
            ['transmission', 'Transmission Service', 'preventive', 60000, 1095, 'settings'],
            ['spark_plugs', 'Spark Plug Replacement', 'preventive', 30000, 730, 'zap'],
            ['wheel_alignment', 'Wheel Alignment', 'preventive', 20000, 365, 'crosshair'],
            ['registration', 'LTO Registration Renewal', 'legal', null, 365, 'file-text'],
            ['insurance', 'Insurance Renewal', 'legal', null, 365, 'shield'],
            ['emission_test', 'Emission Test', 'inspection', null, 365, 'gauge'],
        ];

        foreach ($types as [$code, $name, $category, $km, $days, $icon]) {
            MaintenanceType::updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'default_interval_km' => $km,
                'default_interval_days' => $days,
                'icon' => $icon,
            ]);
        }
    }

    private function seedNotificationTemplates(): void
    {
        $templates = [
            ['price_forecast_weekly', 'Fuel price forecast', '{{direction}} of about ₱{{amount}}/L expected on {{date}} ({{confidence}}% confidence).', ['direction', 'amount', 'date', 'confidence']],
            ['price_alert_hit', 'Cheaper fuel nearby', '{{station}} now sells {{fuel}} at ₱{{price}}/L — {{distance}} km away.', ['station', 'fuel', 'price', 'distance']],
            ['maintenance_due', 'Maintenance due', '{{vehicle}} is due for {{service}} on {{date}}.', ['vehicle', 'service', 'date']],
            ['registration_expiry', 'Registration expiring', '{{vehicle}} registration expires on {{date}}.', ['vehicle', 'date']],
            ['insurance_expiry', 'Insurance expiring', '{{vehicle}} insurance expires on {{date}}.', ['vehicle', 'date']],
            ['fraud_alert', 'Possible fuel anomaly', '{{vehicle}}: {{alert_type}} detected with {{score}} confidence.', ['vehicle', 'alert_type', 'score']],
            ['report_ready', 'Report ready', 'Your {{report}} export has finished generating.', ['report']],
            ['report_approved', 'Report approved', 'Thanks — your price report for {{station}} is now live.', ['station']],
        ];

        foreach ($templates as [$code, $title, $body, $variables]) {
            NotificationTemplate::updateOrCreate(
                ['code' => $code, 'channel' => 'push'],
                ['title' => $title, 'body' => $body, 'variables' => $variables, 'is_active' => true],
            );
        }
    }

    private function seedReportDefinitions(): void
    {
        $definitions = [
            ['fuel_expense_summary', 'Fuel Expense Summary', 'Spend, litres and efficiency by vehicle over a period.', 'user', 'reports.view'],
            ['fleet_utilisation', 'Fleet Utilisation', 'Distance, idle days and cost per km by vehicle.', 'fleet', 'fleet.reports'],
            ['price_movement', 'Regional Price Movement', 'Average pump price and week-on-week change by region.', 'platform', 'reports.platform'],
            ['station_performance', 'Station Performance', 'Price competitiveness and rating trend for a station.', 'station', 'station.reports'],
            ['fraud_register', 'Fraud Alert Register', 'All fraud alerts with status and resolution.', 'company', 'fraud.view'],
        ];

        foreach ($definitions as [$code, $name, $description, $scope, $permission]) {
            ReportDefinition::updateOrCreate(['code' => $code], [
                'name' => $name,
                'description' => $description,
                'scope' => $scope,
                'required_permission' => $permission,
            ]);
        }
    }
}
