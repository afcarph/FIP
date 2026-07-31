<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Base class for expected, business-rule violations.
 *
 * These are surfaced to the client as a 4xx with a stable machine-readable
 * `code`, and are excluded from error reporting because they describe user
 * mistakes rather than defects.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $errorCode = 'domain_error',
        protected int $status = 422,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function context(): array
    {
        return $this->context;
    }
}
