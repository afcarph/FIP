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

    /**
     * The limit actually in force for a company.
     *
     * Three things can move it off the plan's configured number, in order:
     * a negotiated agreement stored against the company, an enterprise
     * selection not yet confirmed (which falls back to the default plan via
     * effectiveTier), and a trial that has run out.
     */
    public function limitForCompany(Company $company, string $resource): ?int
    {
        $negotiated = $company->subscription_limits[$resource] ?? null;

        // A negotiated null is a deliberate "no limit" and must survive; only
        // an absent key falls through to the plan.
        if (is_array($company->subscription_limits) && array_key_exists($resource, $company->subscription_limits)) {
            return $negotiated === null ? null : (int) $negotiated;
        }

        return $this->limitFor($company->effectiveTier(), $resource);
    }

    /** What the company is using now. */
    public function usage(int $companyId, string $resource): int
    {
        return match ($resource) {
            self::VEHICLES => Vehicle::query()->where('company_id', $companyId)->count(),

            self::SEATS => User::query()->where('company_id', $companyId)->count(),

            // A revoked device has been taken out of service, so it should not
            // hold a slot the company is paying for. Nor should a browser:
            // see UserDevice::scopeCountsTowardPlan.
            self::DEVICES => UserDevice::query()
                ->countsTowardPlan()
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

        $company = Company::query()->find($companyId);

        if ($company === null) {
            return;
        }

        $this->assertTrialHasNotLapsed($company, $resource);

        $tier = $company->effectiveTier();
        $limit = $this->limitForCompany($company, $resource);

        if ($limit === null) {
            return;
        }

        $current = $this->usage($companyId, $resource);

        if ($current < $limit) {
            return;
        }

        // A company whose chosen plan is not yet the one being applied would
        // otherwise be told its "free plan" is full, having registered for
        // enterprise — true about the numbers and baffling to read.
        $awaiting = $company->subscription_status === Company::STATUS_PENDING_SETUP
            ? sprintf(
                ' Your %s plan is still being set up, so the standard allowance applies until an administrator confirms it.',
                $company->subscription_tier,
            )
            : '';

        // 402 rather than 403: the caller is authorised and the request is
        // well formed. What is missing is capacity, and saying so lets a client
        // offer an upgrade instead of an access error nobody can act on.
        throw new DomainException(
            sprintf(
                'Your %s plan allows %d %s and %d are in use. Existing records are unaffected; ask an administrator to raise the plan.%s',
                $tier,
                $limit,
                $resource,
                $current,
                $awaiting,
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
        // `$tier` is the fallback for a company row that is no longer there.
        // What is reported otherwise is what is actually in force for the
        // company — its negotiated limits if it has any, the default plan
        // while an enterprise selection awaits confirmation — because "what
        // would this company look like on a plan it is not on" is not a
        // question any caller asks.

        $company = Company::query()->find($companyId);
        $resources = [];

        foreach ([self::VEHICLES, self::SEATS, self::DEVICES] as $resource) {
            $limit = $company !== null
                ? $this->limitForCompany($company, $resource)
                : $this->limitFor($tier, $resource);
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
            'status' => $company?->subscription_status,
            // The plan whose numbers are actually being applied. Equal to
            // `tier` for every ordinary company, and deliberately not equal
            // while an enterprise selection is awaiting confirmation.
            'effective_tier' => $company?->effectiveTier(),
            'trial_ends_at' => $company?->trial_ends_at?->toIso8601String(),
            'trial_expired' => $company?->trialHasExpired() ?? false,
            'has_negotiated_limits' => is_array($company?->subscription_limits),
            'resources' => $resources,
        ];
    }

    /**
     * Refuse a creation once a trial has lapsed, if that is the configured
     * behaviour.
     *
     * Creation only. Nothing existing is removed, hidden or made read-only —
     * the company keeps every record, every screen and every login. Whether a
     * lapsed trial should bite at all is a business decision nobody has taken,
     * so it is configuration, and `none` leaves an expired trial working
     * exactly as it did.
     *
     * @throws DomainException 402 when the trial has run out
     */
    private function assertTrialHasNotLapsed(Company $company, string $resource): void
    {
        if ($company->subscription_status !== Company::STATUS_TRIALING) {
            return;
        }

        if (! $company->trialHasExpired()) {
            return;
        }

        if (config('fip.subscription.trial_expiry') !== 'block_creation') {
            return;
        }

        throw new DomainException(
            sprintf(
                'Your free trial ended on %s, so no further %s can be added. Everything already '
                .'in the account is untouched — ask an administrator to move you onto a plan.',
                $company->trial_ends_at?->toFormattedDateString() ?? 'its end date',
                $resource,
            ),
            'trial_expired',
            402,
        );
    }
}
