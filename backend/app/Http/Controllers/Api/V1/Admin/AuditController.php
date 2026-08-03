<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\User\Models\AuditLog;
use App\Domain\User\Models\LoginAttempt;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(name="Admin — Audit", description="Audit trail, sign-in history and API telemetry")
 */
class AuditController extends Controller
{
    /**
     * @OA\Get(path="/admin/audit-logs", tags={"Admin — Audit"}, security={{"bearerAuth":{}}},
     *   summary="Search the audit trail",
     *
     *   @OA\Parameter(name="event", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="user_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="auditable_type", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Audit entries"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAuditLogs', User::class);

        $paginator = AuditLog::query()
            ->when($request->has('event'), fn ($q) => $q->event($request->string('event')->toString()))
            ->when($request->has('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->has('auditable_type'), fn ($q) => $q->where('auditable_type', $request->string('auditable_type')->toString()))
            ->when($request->has('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->has('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->with('user:id,first_name,last_name,email')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return ApiResponse::paginated($paginator);
    }

    /**
     * @OA\Get(path="/admin/login-attempts", tags={"Admin — Audit"}, security={{"bearerAuth":{}}},
     *   summary="Recent sign-in attempts", @OA\Response(response=200, description="Attempts"))
     */
    public function loginAttempts(Request $request): JsonResponse
    {
        $this->authorize('viewAuditLogs', User::class);

        $paginator = LoginAttempt::query()
            ->when($request->has('email'), fn ($q) => $q->where('email', $request->string('email')->toString()))
            ->when($request->boolean('failed_only'), fn ($q) => $q->where('succeeded', false))
            ->latest('attempted_at')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return ApiResponse::paginated($paginator);
    }

    /**
     * @OA\Get(path="/admin/api-metrics", tags={"Admin — Audit"}, security={{"bearerAuth":{}}},
     *   summary="Endpoint latency and error-rate summary",
     *
     *   @OA\Response(response=200, description="Slowest and most error-prone endpoints"))
     */
    public function apiMetrics(Request $request): JsonResponse
    {
        $this->authorize('viewAuditLogs', User::class);

        $since = now()->subHours((int) $request->integer('hours', 24));

        $rows = DB::table('api_request_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                path,
                COUNT(*) AS calls,
                ROUND(AVG(duration_ms)) AS avg_ms,
                MAX(duration_ms) AS max_ms,
                SUM(status_code >= 500) AS server_errors,
                SUM(status_code BETWEEN 400 AND 499) AS client_errors
            ')
            ->groupBy('path')
            ->orderByDesc('calls')
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'window_hours' => (int) $request->integer('hours', 24),
            'endpoints' => $rows->map(static fn ($row) => [
                'path' => $row->path,
                'calls' => (int) $row->calls,
                'avg_ms' => (int) $row->avg_ms,
                'max_ms' => (int) $row->max_ms,
                'server_errors' => (int) $row->server_errors,
                'client_errors' => (int) $row->client_errors,
                'error_rate' => $row->calls > 0
                    ? round((((int) $row->server_errors + (int) $row->client_errors) / (int) $row->calls) * 100, 2)
                    : 0.0,
            ])->all(),
        ]);
    }
}
