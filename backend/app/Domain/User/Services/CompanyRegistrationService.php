<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Repositories\UserRepository;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registering a client, as opposed to a person.
 *
 * `AuthService::register` creates a private motorist: one account, no company,
 * no fleet. A fleet operator signing up needs three things created together —
 * the company, the subscription it is on, and the administrator who will run
 * it — and needs them to be a single transaction, because a company with no
 * administrator is unreachable and an administrator with no company can see
 * nothing.
 *
 * Two rules the client cannot influence.
 *
 * The plan is validated against the configured tiers here rather than trusted
 * from the request, and the limits behind it are never read from the payload.
 * A client says which plan it wants; the server decides what that means.
 *
 * And the administrator gets `company_manager`, never `super_admin`. That role
 * is platform-level and belongs to whoever runs FIP, not to whoever registers
 * on it.
 */
final class CompanyRegistrationService
{
    public function __construct(private readonly UserRepository $users) {}

    /**
     * @param array<string, mixed> $data
     * @return array{company: Company, admin: User}
     */
    public function register(array $data): array
    {
        $plan = $this->assertSelectablePlan((string) $data['plan']);

        if ($this->users->existsBy('email', mb_strtolower((string) $data['admin']['email']))) {
            throw new DomainException('An account with that email already exists.', 'email_taken', 409);
        }

        return DB::transaction(function () use ($data, $plan): array {
            $company = Company::create([
                'name' => $data['company']['name'],
                'legal_name' => $data['company']['legal_name'] ?? null,
                'tin' => $data['company']['tin'] ?? null,
                'industry' => $data['company']['industry'] ?? null,
                'type' => $data['company']['type'] ?? 'logistics',
                'address_line' => $data['company']['address_line'] ?? null,
                'city_id' => $data['company']['city_id'] ?? null,
                'contact_email' => $data['company']['contact_email'] ?? $data['admin']['email'],
                'contact_phone' => $data['company']['contact_phone'] ?? null,
                'is_active' => true,
                ...$this->subscriptionState($plan),
            ]);

            /** @var User $admin */
            $admin = $this->users->create([
                'first_name' => $data['admin']['first_name'],
                'last_name' => $data['admin']['last_name'],
                'email' => $data['admin']['email'],
                'phone' => $data['admin']['phone'] ?? null,
                'password' => $data['admin']['password'],
                'company_id' => $company->getKey(),
                'status' => 'active',
            ]);

            $admin->assignRole('company_manager');

            UserPreference::create(['user_id' => $admin->getKey(), 'theme' => 'system']);

            Log::channel('security')->info('Company registered', [
                'company_id' => $company->getKey(),
                'plan' => $company->subscription_tier,
                'status' => $company->subscription_status,
                'admin_id' => $admin->getKey(),
            ]);

            return ['company' => $company, 'admin' => $admin];
        });
    }

    /**
     * Move a company onto another plan.
     *
     * Every transition the product supports runs through here: a trial
     * converting to business or enterprise, a business negotiating enterprise
     * terms, or a business renewing — which is the same plan again and is
     * deliberately not a special case.
     *
     * There is no payment gateway in this repository and this does not pretend
     * otherwise. It moves subscription *state*; whatever collects money, when
     * it exists, calls this once it has.
     *
     * @param array<string, int|null>|null $negotiatedLimits overrides for an
     *                                                       agreement that is not what the config file says
     */
    public function changePlan(Company $company, string $plan, ?array $negotiatedLimits = null): Company
    {
        $this->assertSelectablePlan($plan);

        $state = $this->subscriptionState($plan);

        // A trial that has already run is not restarted by moving onto a paid
        // plan, and the dates stay as a record of when it happened. Only a
        // company that has never trialled gets trial dates from here.
        if ($plan !== 'free_trial') {
            unset($state['trial_started_at'], $state['trial_ends_at']);
        }

        // Confirming an enterprise agreement is exactly this: the negotiated
        // numbers arrive, so the plan stops being pending and starts applying.
        if ($negotiatedLimits !== null) {
            $state['subscription_limits'] = $negotiatedLimits;
            $state['subscription_status'] = Company::STATUS_ACTIVE;
        }

        $company->update($state);

        Log::channel('security')->info('Subscription changed', [
            'company_id' => $company->getKey(),
            'plan' => $company->subscription_tier,
            'status' => $company->subscription_status,
            'negotiated' => $negotiatedLimits !== null,
        ]);

        return $company->refresh();
    }

    /**
     * The plans a stranger may choose, and what choosing one means.
     *
     * Enterprise is selectable but not self-serve: its limits are negotiated,
     * so the company is created and works, on the default allowance, until a
     * platform administrator confirms what was actually agreed. Handing
     * negotiated capacity to whoever typed a company name would make the
     * negotiation meaningless.
     *
     * @return array<string, mixed>
     */
    private function subscriptionState(string $plan): array
    {
        if ($plan === 'free_trial') {
            $days = (int) config('fip.subscription.trial_days');

            return [
                'subscription_tier' => $plan,
                'subscription_status' => Company::STATUS_TRIALING,
                'trial_started_at' => now(),
                // Configuration, not a constant: no trial length has been
                // approved, and the default is provisional.
                'trial_ends_at' => now()->addDays($days),
            ];
        }

        if ($plan === 'enterprise') {
            return [
                'subscription_tier' => $plan,
                'subscription_status' => Company::STATUS_PENDING_SETUP,
            ];
        }

        return [
            'subscription_tier' => $plan,
            'subscription_status' => Company::STATUS_ACTIVE,
        ];
    }

    /** A plan nobody configured is not a plan, whatever the client sent. */
    private function assertSelectablePlan(string $plan): string
    {
        $configured = array_keys((array) config('fip.subscription.tiers', []));

        if (! in_array($plan, $configured, true)) {
            throw new DomainException(
                sprintf('[%s] is not a plan you can register on.', $plan),
                'unknown_plan',
                422,
            );
        }

        return $plan;
    }
}
