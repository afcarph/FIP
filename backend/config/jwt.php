<?php

declare(strict_types=1);
use Tymon\JWTAuth\Providers\Auth\Illuminate;
use Tymon\JWTAuth\Providers\JWT\Lcobucci;

return [
    'secret' => env('JWT_SECRET'),
    'keys' => [
        'public' => env('JWT_PUBLIC_KEY'),
        'private' => env('JWT_PRIVATE_KEY'),
        'passphrase' => env('JWT_PASSPHRASE'),
    ],

    // Short-lived access tokens; refresh keeps sessions alive for two weeks.
    'ttl' => (int) env('JWT_TTL', 60),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),

    'algo' => env('JWT_ALGO', 'HS256'),
    'required_claims' => ['iss', 'iat', 'exp', 'nbf', 'sub', 'jti'],
    'persistent_claims' => ['roles', 'company_id'],
    'lock_subject' => true,
    'leeway' => (int) env('JWT_LEEWAY', 0),

    // Invalidated tokens are held until natural expiry so a stolen token
    // cannot be reused after logout.
    'blacklist_enabled' => (bool) env('JWT_BLACKLIST_ENABLED', true),
    'blacklist_grace_period' => (int) env('JWT_BLACKLIST_GRACE_PERIOD', 30),

    'providers' => [
        'jwt' => Lcobucci::class,
        'auth' => Illuminate::class,
        'storage' => Tymon\JWTAuth\Providers\Storage\Illuminate::class,
    ],
];
