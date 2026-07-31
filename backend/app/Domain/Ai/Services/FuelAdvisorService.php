<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Models\AiChatMessage;
use App\Domain\Ai\Models\AiChatSession;
use App\Domain\Expense\Services\FuelExpenseService;
use App\Domain\Station\Repositories\GasStationRepository;
use App\Domain\User\Models\User;
use App\Services\External\AiServiceClient;
use Illuminate\Support\Carbon;

/**
 * The conversational fuel advisor.
 *
 * The assistant is only as good as the context it is handed, so this service
 * assembles a compact, factual snapshot — the user's vehicles, recent
 * efficiency, live nearby prices and this week's forecast — and passes it
 * alongside the question. The model is instructed to answer *from* that
 * context, which keeps answers grounded and citable rather than invented.
 */
final readonly class FuelAdvisorService
{
    public function __construct(
        private AiServiceClient $ai,
        private GasStationRepository $stations,
        private PriceForecastService $forecasts,
        private FuelExpenseService $expenses,
    ) {}

    /**
     * Handle one conversational turn.
     *
     * @param  array{latitude?: float, longitude?: float, vehicle_id?: int}  $context
     */
    public function ask(User $user, string $question, ?AiChatSession $session = null, array $context = []): array
    {
        $session ??= $this->startSession($user, $question);

        AiChatMessage::create([
            'session_id' => $session->getKey(),
            'role' => 'user',
            'content' => $question,
        ]);

        $snapshot = $this->buildContext($user, $context);
        $startedAt = microtime(true);

        $response = $this->ai->chat([
            'question' => $question,
            'history' => $session->transcript(),
            'context' => $snapshot,
            'user' => [
                'name' => $user->first_name,
                'locale' => $user->locale,
                'roles' => $user->getRoleNames()->all(),
            ],
        ]);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $answer = $response['answer'] ?? 'I could not work that one out — try rephrasing?';

        AiChatMessage::create([
            'session_id' => $session->getKey(),
            'role' => 'assistant',
            'content' => $answer,
            'tool_calls' => $response['tool_calls'] ?? null,
            'tokens' => $response['tokens'] ?? null,
            'latency_ms' => $latencyMs,
        ]);

        $session->forceFill([
            'last_message_at' => now(),
            'token_usage' => $session->token_usage + (int) ($response['tokens'] ?? 0),
            'context' => $snapshot,
        ])->save();

        return [
            'session_id' => $session->getKey(),
            'answer' => $answer,
            'suggestions' => $response['suggestions'] ?? $this->defaultSuggestions(),
            'sources' => $response['sources'] ?? [],
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Deterministic answer to "should I refuel today?".
     *
     * This one does not go through the language model at all: the decision is
     * arithmetic on the forecast and the user's tank, so it should be exact
     * and identical every time it is asked.
     */
    public function shouldRefuelToday(User $user, ?int $vehicleId = null, ?float $tankLevelPct = null): array
    {
        $vehicle = $vehicleId !== null
            ? $user->vehicles()->find($vehicleId)
            : $user->vehicles()->first();

        $forecast = collect($this->forecasts->latest())
            ->firstWhere('fuel_type_id', $vehicle?->fuel_type_id);

        $capacity = $vehicle?->tank_capacity;
        $litresToFill = ($capacity !== null && $tankLevelPct !== null)
            ? round($capacity * (1 - min(max($tankLevelPct, 0), 100) / 100), 2)
            : $capacity;

        if ($forecast === null || ! $forecast->isConfident()) {
            return [
                'recommendation' => 'no_strong_signal',
                'headline' => 'No confident forecast this week — refuel when convenient.',
                'confidence' => $forecast?->confidence,
                'estimated_impact' => null,
            ];
        }

        $impact = $litresToFill !== null
            ? round($litresToFill * abs($forecast->change_amount), 2)
            : null;

        $effectiveOn = $this->nextAdjustmentDate()->toFormattedDateString();

        if ($forecast->direction === 'increase') {
            return [
                'recommendation' => 'refuel_now',
                'headline' => sprintf(
                    'Refuel before %s — a ₱%.2f/L increase is expected (%d%% confidence).',
                    $effectiveOn,
                    abs($forecast->change_amount),
                    (int) round($forecast->confidence * 100),
                ),
                'confidence' => $forecast->confidence,
                'estimated_impact' => $impact,
                'impact_direction' => 'saving',
                'effective_on' => $effectiveOn,
                'drivers' => $forecast->topDrivers(),
            ];
        }

        if ($forecast->direction === 'rollback') {
            return [
                'recommendation' => 'wait',
                'headline' => sprintf(
                    'Hold off if you can — a ₱%.2f/L rollback is expected on %s (%d%% confidence).',
                    abs($forecast->change_amount),
                    $effectiveOn,
                    (int) round($forecast->confidence * 100),
                ),
                'confidence' => $forecast->confidence,
                'estimated_impact' => $impact,
                'impact_direction' => 'saving',
                'effective_on' => $effectiveOn,
                'drivers' => $forecast->topDrivers(),
            ];
        }

        return [
            'recommendation' => 'neutral',
            'headline' => 'Prices are expected to hold — refuel when convenient.',
            'confidence' => $forecast->confidence,
            'estimated_impact' => null,
        ];
    }

    /**
     * "Why did my consumption increase?" — compares the two most recent
     * periods and attributes the change to distance, price and efficiency.
     */
    public function explainConsumptionChange(User $user, ?int $vehicleId = null): array
    {
        $scope = $user->fuelPurchases()->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId));

        $now = now();
        $current = $this->expenses->summary(clone $scope, $now->copy()->subDays(30), $now);
        $previous = $this->expenses->summary(clone $scope, $now->copy()->subDays(60), $now->copy()->subDays(30));

        $factors = [];

        $delta = static fn (?float $a, ?float $b): ?float => ($a !== null && $b) ? round((($a - $b) / $b) * 100, 1) : null;

        $distanceChange = $delta($current['total_distance_km'], $previous['total_distance_km']);
        $priceChange = $delta($current['avg_price_per_litre'], $previous['avg_price_per_litre']);
        $efficiencyChange = $delta($current['avg_km_per_litre'], $previous['avg_km_per_litre']);

        if ($distanceChange !== null && abs($distanceChange) >= 5) {
            $factors[] = [
                'factor' => 'distance_travelled',
                'change_pct' => $distanceChange,
                'explanation' => sprintf('You drove %.0f%% %s than the previous month.', abs($distanceChange), $distanceChange > 0 ? 'more' : 'less'),
            ];
        }

        if ($priceChange !== null && abs($priceChange) >= 2) {
            $factors[] = [
                'factor' => 'pump_price',
                'change_pct' => $priceChange,
                'explanation' => sprintf('Average pump price %s %.1f%%.', $priceChange > 0 ? 'rose' : 'fell', abs($priceChange)),
            ];
        }

        if ($efficiencyChange !== null && abs($efficiencyChange) >= 5) {
            $factors[] = [
                'factor' => 'vehicle_efficiency',
                'change_pct' => $efficiencyChange,
                'explanation' => $efficiencyChange < 0
                    ? sprintf('Fuel economy dropped %.1f%% — check tyre pressure, air filter and load.', abs($efficiencyChange))
                    : sprintf('Fuel economy improved %.1f%%.', $efficiencyChange),
            ];
        }

        $costChange = $delta($current['total_cost'], $previous['total_cost']);

        return [
            'current_period' => $current,
            'previous_period' => $previous,
            'cost_change_pct' => $costChange,
            'factors' => $factors,
            'headline' => $factors === []
                ? 'Your fuel spend is broadly unchanged month on month.'
                : sprintf(
                    'Spend %s %.1f%%, driven mainly by %s.',
                    ($costChange ?? 0) > 0 ? 'rose' : 'fell',
                    abs($costChange ?? 0),
                    str_replace('_', ' ', $factors[0]['factor']),
                ),
        ];
    }

    // ------------------------------------------------------------ internals

    private function startSession(User $user, string $firstQuestion): AiChatSession
    {
        return AiChatSession::create([
            'user_id' => $user->getKey(),
            'title' => mb_substr($firstQuestion, 0, 120),
            'last_message_at' => now(),
        ]);
    }

    /** Compact factual snapshot handed to the model with every question. */
    private function buildContext(User $user, array $context): array
    {
        $vehicles = $user->vehicles()->with('fuelType')->limit(5)->get()->map(static fn ($v) => [
            'id' => $v->getKey(),
            'name' => $v->display_name,
            'fuel_type' => $v->fuelType?->name,
            'tank_capacity_l' => $v->tank_capacity,
            'avg_km_per_litre' => $v->avg_km_per_litre,
            'odometer_km' => $v->current_odometer,
        ])->all();

        $snapshot = [
            'currency' => config('fip.expenses.currency_symbol'),
            'today' => now()->toDateString(),
            'vehicles' => $vehicles,
            'forecasts' => collect($this->forecasts->latest())->map(static fn ($f) => [
                'fuel_type' => $f->fuelType?->name,
                'direction' => $f->direction,
                'change_amount' => $f->change_amount,
                'confidence' => $f->confidence,
                'effective_week' => $f->forecast_for?->toDateString(),
                'narrative' => $f->narrative,
            ])->all(),
        ];

        if (isset($context['latitude'], $context['longitude'])) {
            $fuelTypeId = $user->preferences?->preferred_fuel_type_id
                ?? $user->vehicles()->value('fuel_type_id');

            if ($fuelTypeId !== null) {
                $snapshot['nearby_cheapest'] = $this->stations
                    ->cheapestNearby((float) $context['latitude'], (float) $context['longitude'], (int) $fuelTypeId, 5.0, 5)
                    ->map(static fn ($s) => [
                        'station' => $s->name,
                        'brand' => $s->brand?->name,
                        'price' => (float) ($s->current_price ?? 0),
                        'distance_km' => round(((float) ($s->distance_m ?? 0)) / 1000, 2),
                    ])->all();
            }
        }

        $snapshot['spend_last_30_days'] = $this->expenses->summary(
            $user->fuelPurchases(),
            now()->subDays(30),
            now(),
        );

        return $snapshot;
    }

    private function nextAdjustmentDate(): Carbon
    {
        $day = (int) config('fip.forecast.effective_day_of_week');

        return now()->startOfWeek()->addDays($day - 1)->isPast()
            ? now()->startOfWeek()->addWeek()->addDays($day - 1)
            : now()->startOfWeek()->addDays($day - 1);
    }

    /** @return list<string> */
    private function defaultSuggestions(): array
    {
        return [
            'Should I refuel today?',
            'Where is the cheapest diesel near me?',
            'Why did my fuel consumption increase?',
            'How much did I spend on fuel last month?',
        ];
    }
}
