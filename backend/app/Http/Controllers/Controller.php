<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *   title="Fuel Intelligence Platform API",
 *   version="1.0.0",
 *   description="AI-powered fuel price intelligence, expense optimisation and fleet management for the Philippine market.",
 *   @OA\Contact(name="FIP Engineering", email="api@fip.ph"),
 *   @OA\License(name="MIT")
 * )
 *
 * @OA\Server(url="http://localhost:8000/api/v1", description="Local")
 * @OA\Server(url="https://api.fip.ph/api/v1", description="Production")
 *
 * @OA\SecurityScheme(
 *   securityScheme="bearerAuth", type="http", scheme="bearer", bearerFormat="JWT",
 *   description="JWT issued by /auth/login. Send as `Authorization: Bearer <token>`."
 * )
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
