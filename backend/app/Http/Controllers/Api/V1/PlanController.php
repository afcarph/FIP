<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The plans a prospective client can choose between.
 *
 * Public, because the page that shows them is the first thing a stranger sees
 * and there is no account yet to authenticate. `/admin/subscription-tiers`
 * serves the same configuration to platform administrators; this one is the
 * unauthenticated view of it, and the difference is deliberate rather than
 * duplication — the admin endpoint exists to choose a plan *for* a tenant.
 *
 * What it carries, and what it does not.
 *
 * It carries the limits, because a registration page that showed numbers of
 * its own would be a second copy of them, and the two would drift the first
 * time the business changed one. The backend is the only place they live.
 *
 * It carries `is_provisional`, because these numbers are placeholders awaiting
 * a business decision, and a page that presents them as settled invites
 * somebody to sell against them.
 *
 * It carries no pricing at all. None exists in this repository, and inventing
 * a figure on the page a customer signs up from would be the worst possible
 * place to invent one.
 */
class PlanController extends Controller
{
    /**
     * @OA\Get(path="/plans", tags={"Subscriptions"},
     *   summary="Plans available at registration, with their limits",
     *
     *   @OA\Response(response=200, description="Plans"))
     */
    public function index(): JsonResponse
    {
        $tiers = (array) config('fip.subscription.tiers', []);

        $plans = collect($tiers)->map(fn (array $limits, string $name) => [
            'name' => $name,
            'label' => $this->label($name),
            'description' => $this->description($name),
            // Selectable at registration, as opposed to merely configured.
            // `free` is the legacy tier that predates the trial and is not
            // offered to a new client; it stays configured because existing
            // companies are on it.
            'selectable' => $name !== 'free',
            // Negotiated rather than granted: an administrator confirms the
            // real limits before the plan takes effect, so the page can say so
            // instead of implying instant capacity.
            'requires_confirmation' => $name === 'enterprise',
            'limits' => [
                'vehicles' => $limits['vehicles'] ?? null,
                'users' => $limits['seats'] ?? null,
                'devices' => $limits['devices'] ?? null,
            ],
        ])->values()->all();

        return ApiResponse::success([
            'plans' => $plans,
            'default' => (string) config('fip.subscription.default_tier'),
            'trial_days' => (int) config('fip.subscription.trial_days'),
            'is_provisional' => true,
        ]);
    }

    private function label(string $name): string
    {
        return match ($name) {
            'free_trial' => 'Free Trial',
            'free' => 'Free',
            'business' => 'Business',
            'enterprise' => 'Enterprise',
            default => ucfirst(str_replace('_', ' ', $name)),
        };
    }

    private function description(string $name): string
    {
        return match ($name) {
            'free_trial' => 'Try FIP for your fleet',
            'business' => 'For growing fleet operations',
            'enterprise' => 'For larger organizations',
            default => '',
        };
    }
}
