<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AiModelController;
use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\CompanyAdminController;
use App\Http\Controllers\Api\V1\Admin\ModerationController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\CrowdReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceHealthController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\ForecastController;
use App\Http\Controllers\Api\V1\FuelController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OcrController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RouteController;
use App\Http\Controllers\Api\V1\StationController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| API v1
|---------------------------------------------------------------------------
| Three access tiers:
|   • public       — guest browsing (directory, prices, forecasts)
|   • auth:api     — any signed-in user
|   • permission:* — role/permission gated administration
|
| Rate limits are declared per group in RouteServiceProvider and tuned in
| config/fip.php so operations can adjust them without a deploy.
*/

Route::prefix('v1')->group(function (): void {

    // ------------------------------------------------------------ public ---

    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/mfa/verify', [AuthController::class, 'verifyMfa']);
        Route::post('auth/biometric/challenge', [AuthController::class, 'biometricChallenge']);
        Route::post('auth/biometric', [AuthController::class, 'biometric']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
        Route::get('auth/{provider}/redirect', [AuthController::class, 'socialRedirect'])
            ->whereIn('provider', ['google', 'apple']);
        Route::get('auth/{provider}/callback', [AuthController::class, 'socialCallback'])
            ->whereIn('provider', ['google', 'apple']);
    });

    Route::middleware('throttle:public')->group(function (): void {
        // Readiness: database, scheduler, disk and the PDF archive. Distinct
        // from the liveness probe at the bottom of this file, which must not
        // touch the database — a liveness check that does restarts healthy
        // containers every time the database hiccups.
        Route::get('health', [HealthController::class, 'show']);

        // Station directory
        Route::get('stations', [StationController::class, 'index']);
        Route::get('stations/nearby', [StationController::class, 'nearby']);
        Route::get('stations/cheapest', [StationController::class, 'cheapest']);
        Route::get('stations/{slug}', [StationController::class, 'show']);
        Route::get('stations/{station}/prices/history', [PriceController::class, 'stationHistory']);

        // Price intelligence
        Route::get('prices/fuel-types', [PriceController::class, 'fuelTypes']);
        Route::get('prices/comparison', [PriceController::class, 'comparison']);
        Route::get('prices/trend', [PriceController::class, 'trend']);
        Route::get('prices/advisories', [PriceController::class, 'advisories']);
        Route::get('prices/heat-map', [PriceController::class, 'heatMap']);
        Route::get('prices/regional-movement', [PriceController::class, 'regionalMovement']);

        // Forecasts are public — they are the platform's shop window.
        Route::get('forecasts', [ForecastController::class, 'index']);
        Route::get('forecasts/history', [ForecastController::class, 'history']);
        Route::get('forecasts/accuracy', [ForecastController::class, 'accuracy']);

        // The DOE's published weekly price monitoring reports. Distinct from
        // /prices above, which is the platform's own per-station view: these
        // are the department's figures for a city, brand and week, as
        // published. Literal segments precede the parameterised ones so
        // /fuel/latest is not read as a region named "latest".
        Route::get('fuel/latest', [FuelController::class, 'latest']);
        Route::get('fuel/history', [FuelController::class, 'history']);
        Route::get('fuel/areas', [FuelController::class, 'areas']);
        Route::get('fuel/brands', [FuelController::class, 'brands']);
        Route::get('fuel/search', [FuelController::class, 'search']);
        Route::get('fuel/trends', [FuelController::class, 'trends']);
        Route::get('fuel/reports', [FuelController::class, 'reports']);
        Route::get('fuel/imports', [FuelController::class, 'imports']);

        // Community reports (read-only for guests)
        Route::get('reports', [CrowdReportController::class, 'index']);

        // Reference data
        Route::get('maintenance/types', [MaintenanceController::class, 'types']);
    });

    // ----------------------------------------------------- authenticated ---

    Route::middleware(['auth:api', 'throttle:authenticated'])->group(function (): void {

        // Session
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // MFA
        Route::post('auth/mfa/enrol', [MfaController::class, 'enrol']);
        Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
        Route::delete('auth/mfa', [MfaController::class, 'disable']);

        // Profile
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'changePassword']);
        Route::put('profile/preferences', [ProfileController::class, 'updatePreferences']);
        Route::post('profile/biometric/enrol', [ProfileController::class, 'enrolBiometric']);
        Route::delete('profile', [ProfileController::class, 'destroy']);

        // Dashboards
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/executive', [DashboardController::class, 'executive']);

        // Vehicles
        Route::apiResource('vehicles', VehicleController::class);
        Route::post('vehicles/{vehicle}/odometer', [VehicleController::class, 'recordOdometer']);
        Route::get('vehicles/{vehicle}/alerts', [VehicleController::class, 'alerts']);
        Route::get('vehicles/{vehicle}/location', [VehicleController::class, 'location']);
        Route::get('vehicles/{vehicle}/fuel-readings', [VehicleController::class, 'fuelReadings']);
        Route::post('vehicles/{vehicle}/fuel-readings', [VehicleController::class, 'recordFuelReading']);
        Route::get('vehicles/{vehicle}/efficiency', [VehicleController::class, 'efficiency']);

        // Maintenance
        Route::get('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'index']);
        Route::post('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'store']);
        Route::post('vehicles/{vehicle}/maintenance/predict', [MaintenanceController::class, 'predict']);
        Route::get('maintenance/due', [MaintenanceController::class, 'due']);

        // Expenses
        Route::get('expenses/summary', [ExpenseController::class, 'summary']);
        // Reading a receipt is as expensive as any other OCR call, so it takes
        // the same tighter budget rather than the general authenticated one.
        Route::middleware('throttle:ocr')->group(function (): void {
            Route::post('expenses/scan-receipt', [ExpenseController::class, 'scanReceipt']);
        });
        Route::apiResource('expenses', ExpenseController::class)->except(['show']);

        // Station management
        Route::post('stations', [StationController::class, 'store']);
        Route::put('stations/{station}', [StationController::class, 'update']);
        Route::delete('stations/{station}', [StationController::class, 'destroy']);
        Route::post('stations/{station}/rate', [StationController::class, 'rate']);
        Route::put('stations/{station}/prices', [PriceController::class, 'update']);

        // Crowd sourcing
        Route::get('reports/mine', [CrowdReportController::class, 'mine']);
        Route::post('reports', [CrowdReportController::class, 'store']);
        Route::post('reports/{report}/vote', [CrowdReportController::class, 'vote']);

        // OCR scanning — expensive, so it carries its own tighter limit.
        Route::middleware('throttle:ocr')->group(function (): void {
            Route::post('ocr/scan', [OcrController::class, 'scan']);
        });
        Route::get('ocr/scans', [OcrController::class, 'index']);
        Route::get('ocr/scans/{scan}', [OcrController::class, 'show']);

        // AI advisor
        Route::middleware('throttle:ai')->group(function (): void {
            Route::post('assistant/chat', [AssistantController::class, 'chat']);
            Route::post('routes/optimize', [RouteController::class, 'optimize']);
        });
        Route::get('assistant/should-i-refuel', [AssistantController::class, 'shouldRefuel']);
        Route::get('assistant/consumption-explainer', [AssistantController::class, 'explainConsumption']);
        Route::get('assistant/sessions', [AssistantController::class, 'sessions']);
        Route::get('assistant/sessions/{session}', [AssistantController::class, 'transcript']);
        Route::get('routes', [RouteController::class, 'index']);

        // Devices — registration and location reporting from the driver app.
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::patch('devices/{device}', [DeviceController::class, 'update']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        // Location ingestion carries its own budget: a fleet flushing offline
        // queues is a different traffic shape from someone browsing the app,
        // and sharing a limiter would let one starve the other.
        Route::middleware('throttle:location')->group(function (): void {
            Route::post('devices/location', [DeviceController::class, 'storeLocation']);

            // Health rides the same budget as location. It is sent from the
            // same timer by the same devices, so a separate limiter would only
            // let one starve the other.
            Route::post('devices/health', [DeviceController::class, 'reportHealth']);
        });

        // Fleet
        Route::prefix('fleet')->group(function (): void {
            Route::get('/', [FleetController::class, 'index']);
            Route::get('dashboard', [FleetController::class, 'dashboard']);
            Route::get('drivers', [FleetController::class, 'drivers']);
            // Adding a driver used to require a direct database insert; the
            // drivers.manage permission existed but no route consumed it.
            Route::post('drivers', [FleetController::class, 'storeDriver']);
            Route::patch('drivers/{driver}', [FleetController::class, 'updateDriver']);
            Route::post('assignments', [FleetController::class, 'assign']);
            // Releasing is its own verb rather than assigning to nobody: the
            // row is kept and dated, because fuel and fraud reporting read who
            // drove what between which dates.
            Route::delete('vehicles/{vehicle}/assignment', [FleetController::class, 'releaseAssignment']);

            // Trips. The lifecycle verbs are POSTs on the trip rather than a
            // PATCH of `status`, so an invalid move is a route that refuses
            // rather than a field that silently accepts anything.
            Route::get('trips', [TripController::class, 'index']);
            Route::get('trips/summary', [TripController::class, 'summary']);
            Route::post('trips', [TripController::class, 'store']);
            Route::get('trips/{trip}', [TripController::class, 'show']);
            Route::post('trips/{trip}/dispatch', [TripController::class, 'dispatchTrip']);
            Route::post('trips/{trip}/start', [TripController::class, 'start']);
            Route::post('trips/{trip}/complete', [TripController::class, 'complete']);
            Route::post('trips/{trip}/cancel', [TripController::class, 'cancel']);
            Route::get('locations', [FleetController::class, 'vehicleLocations']);
            Route::get('vehicles/{vehicle}/locations', [FleetController::class, 'vehicleLocationHistory']);
            Route::get('fraud-alerts', [FleetController::class, 'fraudAlerts']);
            Route::patch('fraud-alerts/{alert}', [FleetController::class, 'resolveFraudAlert']);

            // Device health. Read-only, and authorised in the controller by
            // UserDevicePolicy rather than by this prefix — sitting under
            // /fleet is routing, not permission.
            Route::get('devices', [DeviceHealthController::class, 'index']);
            Route::get('devices/{device}', [DeviceHealthController::class, 'show']);
        });

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/devices', [NotificationController::class, 'registerDevice']);
        Route::get('price-alerts', [NotificationController::class, 'alerts']);
        Route::post('price-alerts', [NotificationController::class, 'storeAlert']);
        Route::delete('price-alerts/{alert}', [NotificationController::class, 'destroyAlert']);

        // Reports
        Route::get('reports/definitions', [ReportController::class, 'definitions']);
        Route::get('reports/runs', [ReportController::class, 'runs']);
        Route::get('reports/runs/{run}', [ReportController::class, 'show']);
        // Streamed through the app so the file is reachable from a browser and
        // stays behind authorization. See ReportController::download().
        Route::get('reports/runs/{run}/download', [ReportController::class, 'download']);
        Route::middleware('throttle:reports')->group(function (): void {
            Route::post('reports/generate', [ReportController::class, 'generate']);
        });
    });

    // ------------------------------------------------------------- admin ---

    Route::middleware(['auth:api', 'throttle:authenticated'])->prefix('admin')->group(function (): void {

        Route::middleware('permission:prices.moderate')->group(function (): void {
            Route::get('moderation/queue', [ModerationController::class, 'queue']);
            Route::post('moderation/reports/{report}/approve', [ModerationController::class, 'approve']);
            Route::post('moderation/reports/{report}/reject', [ModerationController::class, 'reject']);
            Route::get('moderation/ocr', [ModerationController::class, 'ocrQueue']);
            Route::post('moderation/ocr/{scan}/approve', [ModerationController::class, 'approveOcr']);
            Route::post('moderation/ocr/{scan}/reject', [ModerationController::class, 'rejectOcr']);
        });

        Route::middleware('role_or_permission:super_admin|system_admin|users.view')->group(function (): void {
            // Tenants. No destroy: deleting a company would orphan its
            // users, vehicles and devices, and is_active already expresses
            // "stop using this one" without destroying what it owns.
            // Served rather than duplicated in the client: the console used to
            // hardcode the tier list, so adding one in config would not appear
            // and renaming one would offer a value the API refuses.
            Route::get('subscription-tiers', [CompanyAdminController::class, 'tiers']);
            Route::get('companies', [CompanyAdminController::class, 'index']);
            Route::post('companies', [CompanyAdminController::class, 'store']);
            Route::get('companies/{company}', [CompanyAdminController::class, 'show']);
            Route::patch('companies/{company}', [CompanyAdminController::class, 'update']);

            Route::apiResource('users', UserAdminController::class)->except(['show']);
            Route::get('roles', [UserAdminController::class, 'roles']);
            Route::put('roles/{role}/permissions', [UserAdminController::class, 'syncRolePermissions']);
        });

        Route::middleware('role_or_permission:super_admin|system_admin|audit.view')->group(function (): void {
            // Reports filesystem paths, disk capacity and the database driver,
            // which are useful to an operator and to nobody else.
            Route::get('system', [HealthController::class, 'system']);

            Route::get('audit-logs', [AuditController::class, 'index']);
            Route::get('login-attempts', [AuditController::class, 'loginAttempts']);
            Route::get('api-metrics', [AuditController::class, 'apiMetrics']);
        });

        // Retention decides what the platform deletes, so it sits behind the
        // same permission that guards every other persisted setting.
        Route::middleware('role_or_permission:super_admin|system_admin|settings.manage')->group(function (): void {
            Route::get('settings/privacy', [SettingsController::class, 'privacy']);
            Route::put('settings/privacy/location-retention', [SettingsController::class, 'updateLocationRetention']);
        });

        Route::middleware('role_or_permission:super_admin|system_admin|ai.manage')->group(function (): void {
            Route::get('ai/models', [AiModelController::class, 'index']);
            Route::patch('ai/models/{model}/activate', [AiModelController::class, 'activate']);
            Route::post('ai/forecast/run', [AiModelController::class, 'runForecast']);
            Route::post('ai/forecast/score', [AiModelController::class, 'scoreForecasts']);
        });
    });
});

// Liveness probe for the load balancer — deliberately outside /v1.
Route::get('health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'fip-api',
    'time' => now()->toIso8601String(),
]));
