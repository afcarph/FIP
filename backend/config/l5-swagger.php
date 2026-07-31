<?php

declare(strict_types=1);

return [
    'default' => 'default',

    'documentations' => [
        'default' => [
            'api' => ['title' => 'Fuel Intelligence Platform API'],
            'routes' => ['api' => 'api/documentation'],
            'paths' => [
                'use_absolute_path' => true,
                'docs_json' => 'api-docs.json',
                'docs_yaml' => 'api-docs.yaml',
                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
                'annotations' => [base_path('app/Http/Controllers')],
            ],
        ],
    ],

    'defaults' => [
        'routes' => [
            'docs' => 'docs',
            'oauth2_callback' => 'api/oauth2-callback',
            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],
            'group_options' => [],
        ],
        'paths' => [
            'docs' => storage_path('api-docs'),
            'views' => base_path('resources/views/vendor/l5-swagger'),
            'base' => env('L5_SWAGGER_BASE_PATH', '/api/v1'),
            'excludes' => [],
        ],
        'scanOptions' => ['analyser' => null, 'analysis' => null, 'processors' => [], 'pattern' => null, 'exclude' => []],
        'securityDefinitions' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'JWT issued by POST /auth/login.',
                ],
            ],
            'security' => [],
        ],
        // Regenerating on every request is convenient locally and wasteful in
        // production, where the spec is built at deploy time.
        'generate_always' => (bool) env('L5_SWAGGER_GENERATE_ALWAYS', false),
        'generate_yaml_copy' => true,
        'proxy' => false,
        'additional_config_url' => null,
        'operations_sort' => null,
        'validator_url' => null,
        'ui' => [
            'display' => ['doc_expansion' => 'list', 'filter' => true],
            'authorization' => ['persist_authorization' => true],
        ],
        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', 'http://localhost:8000/api/v1'),
        ],
    ],
];
