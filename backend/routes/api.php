<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AiModelController;
use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\ModerationController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\CrowdReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\ForecastController;
use App\Http\Controllers\Api\V1\FuelController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OcrController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RouteController;
use App\Http\Controllers\Api\V1\StationController;
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
        Route::get('vehicles/{vehicle}/efficiency', [VehicleController::class, 'efficiency']);

        // Maintenance
        Route::get('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'index']);
        Route::post('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'store']);
        Route::post('vehicles/{vehicle}/maintenance/predict', [MaintenanceController::class, 'predict']);
        Route::get('maintenance/due', [MaintenanceController::class, 'due']);

        // Expenses
        Route::get('expenses/summary', [ExpenseController::class, 'summary']);
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

        // Fleet
        Route::prefix('fleet')->group(function (): void {
            Route::get('/', [FleetController::class, 'index']);
            Route::get('dashboard', [FleetController::class, 'dashboard']);
            Route::get('drivers', [FleetController::class, 'drivers']);
            Route::post('assignments', [FleetController::class, 'assign']);
            Route::get('fraud-alerts', [FleetController::class, 'fraudAlerts']);
            Route::patch('fraud-alerts/{alert}', [FleetController::class, 'resolveFraudAlert']);
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
            Route::apiResource('users', UserAdminController::class)->except(['show']);
            Route::get('roles', [UserAdminController::class, 'roles']);
            Route::put('roles/{role}/permissions', [UserAdminController::class, 'syncRolePermissions']);
        });

        Route::middleware('role_or_permission:super_admin|system_admin|audit.view')->group(function (): void {
            Route::get('audit-logs', [AuditController::class, 'index']);
            Route::get('login-attempts', [AuditController::class, 'loginAttempts']);
            Route::get('api-metrics', [AuditController::class, 'apiMetrics']);
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
