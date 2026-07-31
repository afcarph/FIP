<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-tenant guard for company-scoped routes.
 *
 * A caller may only address a `company` route parameter belonging to their own
 * tenant; platform administrators bypass the check. This is a coarse gate —
 * per-record authorisation still runs through policies.
 */
class EnsureCompanyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if ($user->isPlatformAdministrator()) {
            return $next($request);
        }

        if ($user->company_id === null) {
            return ApiResponse::error('no_company', 'Your account is not linked to a company.', 403);
        }

        $requested = $request->route('company');
        $requestedId = is_object($requested) ? $requested->getKey() : $requested;

        if ($requestedId !== null && (int) $requestedId !== (int) $user->company_id) {
            return ApiResponse::error('cross_tenant_denied', 'This resource belongs to another organisation.', 403);
        }

        return $next($request);
    }
}
