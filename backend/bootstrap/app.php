<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCompanyScope;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SecurityHeaders::class,
            LogApiRequest::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'company.scope' => EnsureCompanyScope::class,
        ]);

        // Trust the reverse proxy (Nginx / Cloudflare) for scheme + client IP.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $e, $request) => ApiExceptionRenderer::render($e, $request));

        $exceptions->dontReport([
            DomainException::class,
        ]);
    })
    ->create();
