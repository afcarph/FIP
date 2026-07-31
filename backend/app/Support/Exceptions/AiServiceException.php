<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class AiServiceException extends DomainException
{
    public static function unavailable(string $endpoint, ?string $reason = null): self
    {
        return new self(
            'The AI service is temporarily unavailable. Please try again shortly.',
            'ai_service_unavailable',
            503,
            array_filter(['endpoint' => $endpoint, 'reason' => $reason]),
        );
    }

    public static function badResponse(string $endpoint, int $status): self
    {
        return new self(
            'The AI service returned an unexpected response.',
            'ai_service_error',
            502,
            ['endpoint' => $endpoint, 'upstream_status' => $status],
        );
    }
}
