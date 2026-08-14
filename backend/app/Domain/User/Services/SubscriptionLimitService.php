<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;

/**
 * How much capacity a company's subscription tier allows.
 *
 * Capacity only — this class knows nothing about money. There is no billing
 * provider, no invoice and no payment state, and a tier is set by a platform
 * administrator rather than earned by a transaction.
 *
 * Limits bite at creation and nowhere else. A company that drops to a smaller
 * tier keeps everything it already has: nothing is deleted, hidden or made
 * read-only. In a product where a driver's phone reports their position and a
 * manager watches a live map, a billing state must never be able to take a
 * vehicle away mid-shift.
 */
final class SubscriptionLimitService
{
    public const VEHICLES = 'vehicles';

    public const SEATS = 'seats';

    public const DEVICES = 'devices';

    /**
     * The limit for one resource, or null when the tier is unlimited.
     *
     * An unknown tier falls back to the default rather than to "unlimited": a
     * typo in a tier name should cost a tenant capacity they can ask for, not
     * silently hand them an unbounded allowance.
     */
    public function limitFor(?string $tier, string $resource): ?int
    {
        $tiers = (array) config('fip.subscription.tiers', []);
        $fallback = (string) config('fip.subscription.default_tier', 'free');

        $limits = $tiers[$tier ?? $fallback] ?? $tiers[$fallback] ?? [];

        return $limits[$resource] ?? null;
    }

    /** What the company is using now. */
    public function usage(int $companyId, string $resource): int
    {
        return match ($resource) {
            self::VEHICLES => Vehicle::query()->where('company_id', $companyId)->count(),

            self::SEATS => User::query()->where('company_id', $companyId)->count(),

            // A revoked device has been taken out of service, so it should not
            // hold a slot the company is paying for.
            self::DEVICES => UserDevice::query()
                ->whereNull('revoked_at')
                ->whereIn('user_id', User::query()->where('company_id', $companyId)->select('id'))
                ->count(),

            default => 0,
        };
    }

    /**
     * Refuse a creation that would take the company past its tier.
     *
     * Does nothing for a user with no company: a private motorist registering
     * their own car is not a tenant, and platform administrators are not billed.
     *
     * @throws DomainException 402 when the tier is exhausted
     */
    public function assertCanAdd(?User $user, string $resource): void
    {
        $this->assertCompanyCanAdd($user?->company_id, $resource);
    }

    /**
     * As assertCanAdd, but for a company named directly rather than inferred
     * from the caller. A platform administrator adding somebody to a tenant
     * spends that tenant's seat, not their own.
     *
     * @throws DomainException 402 when the tier is exhausted
     */
    public function assertCompanyCanAdd(?int $companyId, string $resource): void
    {
        if ($companyId === null) {
            return;
        }

        $tier = Company::query()->whereKey($companyId)->value('subscription_tier');
        $limit = $this->limitFor($tier, $resource);

        if ($limit === null) {
            return;
        }

        $current = $this->usage($companyId, $resource);

        if ($current < $limit) {
            return;
        }

        // 402 rather than 403: the caller is authorised and the request is
        // well formed. What is missing is capacity, and saying so lets a client
        // offer an upgrade instead of an access error nobody can act on.
        throw new DomainException(
            sprintf(
                'Your %s plan allows %d %s and %d are in use. Existing records are unaffected; ask an administrator to raise the plan.',
                $tier ?? config('fip.subscription.default_tier'),
                $limit,
                $resource,
                $current,
            ),
            'subscription_limit_reached',
            402,
        );
    }

    /**
     * Usage against limits for one company, for an admin screen.
     *
     * `over_limit` can be true without anything being blocked yet — a tier
     * reduced below current usage leaves the company over until it shrinks,
     * and that is a state worth showing rather than enforcing retroactively.
     *
     * @return array<string, mixed>
     */
    public function describe(int $companyId, ?string $tier): array
    {
        $resources = [];

        foreach ([self::VEHICLES, self::SEATS, self::DEVICES] as $resource) {
            $limit = $this->limitFor($tier, $resource);
            $used = $this->usage($companyId, $resource);

            $resources[$resource] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'over_limit' => $limit !== null && $used > $limit,
            ];
        }

        return [
            'tier' => $tier ?? config('fip.subscription.default_tier'),
            'is_provisional' => true,
            'resources' => $resources,
        ];
    }
}
