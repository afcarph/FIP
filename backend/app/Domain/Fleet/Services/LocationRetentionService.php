<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\User\Models\Setting;
use App\Domain\User\Services\SettingsService;
use App\Support\Exceptions\DomainException;

/**
 * How long vehicle location history is kept.
 *
 * The period is a business and privacy decision, not an engineering one, so
 * this class exists to stop the platform pretending otherwise. It answers two
 * separate questions that are easy to conflate:
 *
 *   - how many days does the pruner use  (there is always an answer)
 *   - has anybody actually approved that (usually not)
 *
 * The environment variable remains as an installation fallback, but it cannot
 * override a value an administrator has explicitly set. An operator editing a
 * `.env` should not be able to silently undo a decision made in the admin UI.
 */
final class LocationRetentionService
{
    public const GROUP = 'privacy';

    public const KEY = 'location_retention_days';

    /** Never approved by anybody — running on the fallback. */
    public const STATUS_PROVISIONAL = 'provisional';

    /** An administrator set this deliberately. */
    public const STATUS_APPROVED = 'approved';

    /** Set in both places, and they disagree. */
    public const STATUS_REQUIRES_REVIEW = 'requires_review';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The number of days the pruner should use.
     *
     * Zero or less means "prune nothing", which is what an unconfigured
     * installation gets if it also clears the fallback. Deleting a driver's
     * movement history because a setting was missing would be the worst
     * possible reading of an absent value.
     */
    public function days(): int
    {
        $configured = $this->settings->get(self::GROUP, self::KEY);

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        return (int) config('fip.location.retention_days');
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured(self::GROUP, self::KEY);
    }

    public function status(): string
    {
        if (! $this->isConfigured()) {
            return self::STATUS_PROVISIONAL;
        }

        $fallback = (int) config('fip.location.retention_days');
        $configured = $this->settings->get(self::GROUP, self::KEY);

        // Both set and disagreeing is not an error — the admin value wins — but
        // somebody should reconcile them before the next person reads the .env
        // and believes it.
        if ($fallback > 0 && $fallback !== $configured) {
            return self::STATUS_REQUIRES_REVIEW;
        }

        return self::STATUS_APPROVED;
    }

    public function maximumDays(): int
    {
        return (int) config('fip.location.retention_max_days');
    }

    /**
     * @throws DomainException when the period is outside the permitted range
     */
    public function update(int $days, ?int $userId): Setting
    {
        $max = $this->maximumDays();

        // Deliberately no zero. Disabling pruning entirely is a decision with
        // no expiry date, and the admin UI is the wrong place to make it — an
        // installation that truly wants it can still clear the fallback.
        if ($days < 1) {
            throw new DomainException(
                'The retention period must be at least one day.',
                'retention_period_too_short',
                422,
            );
        }

        if ($days > $max) {
            throw new DomainException(
                sprintf('The retention period may not exceed %d days.', $max),
                'retention_period_too_long',
                422,
            );
        }

        return $this->settings->set(self::GROUP, self::KEY, $days, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'days' => $this->days(),
            'status' => $this->status(),
            'is_configured' => $this->isConfigured(),
            'minimum_days' => 1,
            'maximum_days' => $this->maximumDays(),
            'fallback_days' => (int) config('fip.location.retention_days'),
            'requires_approval' => $this->status() !== self::STATUS_APPROVED,
        ];
    }
}
