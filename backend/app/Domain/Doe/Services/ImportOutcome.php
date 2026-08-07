<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

/**
 * What one batch import did.
 *
 * Mutable by design — it is accumulated across a few thousand records inside
 * {@see DoeImportService} and then read once. A readonly value object here
 * would mean rebuilding it per price.
 */
final class ImportOutcome
{
    /**
     * @param array<string, true> $unknownFuels fuel codes with no platform fuel type
     * @param list<string> $rejected readings PriceService refused
     */
    public function __construct(
        public int $parsed = 0,
        public int $imported = 0,
        public int $skipped = 0,
        public int $unmatched = 0,
        public array $unknownFuels = [],
        public array $rejected = [],
        public bool $failed = false,
        public ?string $error = null,
    ) {}

    public function summary(): string
    {
        if ($this->failed) {
            return 'failed: '.($this->error ?? 'unknown error');
        }

        return sprintf(
            'parsed=%d imported=%d skipped=%d unmatched=%d',
            $this->parsed,
            $this->imported,
            $this->skipped,
            $this->unmatched,
        );
    }
}
