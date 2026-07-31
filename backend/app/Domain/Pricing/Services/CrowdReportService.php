<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Pricing\Models\PriceReportVote;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Support\Concerns\GeoDistance;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InvalidPriceReportException;
use Illuminate\Support\Facades\DB;

/**
 * Crowd-sourcing pipeline with a three-stage trust model:
 *
 *   1. **Hard gates** — geofence and price sanity. A submission that fails
 *      either is rejected outright and never reaches moderation.
 *   2. **Auto-approval** — reporters whose historical accuracy clears the
 *      configured trust floor publish immediately.
 *   3. **Corroboration** — two independent pending reports agreeing on the
 *      same price promote each other without a human in the loop.
 *
 * Everything else queues for an administrator.
 */
final readonly class CrowdReportService
{
    use GeoDistance;

    public function __construct(
        private PriceService $priceService,
        private PriceRepository $priceRepository,
    ) {}

    public function submit(User $user, GasStation $station, array $data): PriceReport
    {
        $this->assertNotDuplicate($user, $station);

        $distance = $this->resolveDistance($station, $data);
        $trust = $user->trustScore();

        if ($data['report_type'] === 'price') {
            $this->assertPriceWithinBand($station, (int) $data['fuel_type_id'], (float) $data['price']);
        }

        $report = PriceReport::create([
            'station_id' => $station->getKey(),
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'user_id' => $user->getKey(),
            'report_type' => $data['report_type'],
            'price' => $data['price'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'comment' => $data['comment'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'distance_m' => $distance,
            'trust_score' => $trust,
            'status' => PriceReport::STATUS_PENDING,
        ]);

        if ($report->report_type !== 'price') {
            return $report;   // operational reports are informational only
        }

        if ($trust >= (float) config('fip.pricing.crowd_auto_approve_trust')) {
            return $this->publish($report, PriceReport::STATUS_AUTO_APPROVED);
        }

        if ($this->hasCorroboration($report)) {
            return $this->publish($report, PriceReport::STATUS_AUTO_APPROVED);
        }

        return $report;
    }

    /** Administrator approves a queued report. */
    public function approve(PriceReport $report, User $moderator): PriceReport
    {
        if ($report->isPublished()) {
            return $report;
        }

        $report->forceFill(['moderated_by' => $moderator->getKey(), 'moderated_at' => now()])->save();

        return $this->publish($report, PriceReport::STATUS_APPROVED);
    }

    public function reject(PriceReport $report, User $moderator, string $reason): PriceReport
    {
        $report->update([
            'status' => PriceReport::STATUS_REJECTED,
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $report;
    }

    /** Community up/down vote; three net downvotes flag a pending report. */
    public function vote(PriceReport $report, User $user, int $vote): PriceReport
    {
        if (! in_array($vote, [-1, 1], true)) {
            throw new DomainException('Vote must be either +1 or -1.', 'invalid_vote');
        }

        if ($report->user_id === $user->getKey()) {
            throw new DomainException('You cannot vote on your own report.', 'self_vote');
        }

        DB::transaction(function () use ($report, $user, $vote): void {
            PriceReportVote::updateOrCreate(
                ['price_report_id' => $report->getKey(), 'user_id' => $user->getKey()],
                ['vote' => $vote],
            );

            $report->forceFill([
                'upvotes' => $report->votes()->where('vote', 1)->count(),
                'downvotes' => $report->votes()->where('vote', -1)->count(),
            ])->save();
        });

        if ($report->status === PriceReport::STATUS_PENDING && $report->communityScore() <= -3) {
            $report->update(['status' => PriceReport::STATUS_FLAGGED]);
        }

        return $report->refresh();
    }

    // ------------------------------------------------------------ internals

    private function publish(PriceReport $report, string $status): PriceReport
    {
        if ($report->price === null || $report->fuel_type_id === null) {
            return $report;
        }

        $this->priceService->recordPrice(
            station: $report->station,
            fuelTypeId: $report->fuel_type_id,
            price: (float) $report->price,
            source: 'crowd',
            confidence: (float) $report->trust_score,
            reportedBy: $report->user_id,
        );

        $report->update(['status' => $status]);

        return $report;
    }

    /**
     * A second, independent pending report quoting the same price within the
     * last two hours is treated as corroboration and both are published.
     */
    private function hasCorroboration(PriceReport $report): bool
    {
        $required = (int) config('fip.pricing.corroborations_required');

        $matching = PriceReport::query()
            ->where('station_id', $report->station_id)
            ->where('fuel_type_id', $report->fuel_type_id)
            ->where('report_type', 'price')
            ->where('id', '!=', $report->getKey())
            ->where('user_id', '!=', $report->user_id)
            ->where('created_at', '>=', now()->subHours(2))
            ->whereBetween('price', [(float) $report->price - 0.05, (float) $report->price + 0.05])
            ->get();

        if ($matching->count() < $required - 1) {
            return false;
        }

        $matching->each(fn (PriceReport $peer) => $peer->update(['status' => PriceReport::STATUS_AUTO_APPROVED]));

        return true;
    }

    private function assertNotDuplicate(User $user, GasStation $station): void
    {
        $recent = PriceReport::query()
            ->where('user_id', $user->getKey())
            ->where('station_id', $station->getKey())
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($recent) {
            throw InvalidPriceReportException::duplicate();
        }
    }

    private function resolveDistance(GasStation $station, array $data): ?int
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        $distance = (int) round($this->distanceInMetres(
            (float) $data['latitude'],
            (float) $data['longitude'],
            $station->latitude,
            $station->longitude,
        ));

        $allowed = (int) config('fip.pricing.max_report_distance_m');

        if ($distance > $allowed) {
            throw InvalidPriceReportException::tooFarFromStation($distance, $allowed);
        }

        return $distance;
    }

    private function assertPriceWithinBand(GasStation $station, int $fuelTypeId, float $price): void
    {
        $median = $this->priceRepository->cityMedian($station->city_id, $fuelTypeId);

        if ($median === null) {
            return;   // first price recorded in this city — nothing to compare against
        }

        $tolerance = (float) config('fip.pricing.max_price_deviation_pct');

        if (abs($price - $median) / $median > $tolerance) {
            throw InvalidPriceReportException::priceOutOfRange($price, $median, $tolerance);
        }
    }
}
