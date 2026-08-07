<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Data\DoeRecord;
use App\Domain\Doe\Data\StationMatch;
use App\Domain\Doe\Models\DoeStationReview;
use App\Domain\Station\Models\GasStation;
use Illuminate\Support\Collection;

/**
 * Resolves a DOE listing to a station in the platform's directory.
 *
 * The DOE issues no station identifiers, so this is fundamentally a name and
 * address match, and that is where an importer like this quietly goes wrong.
 * Everything here is shaped by one asymmetry: an unmatched station is
 * *visible* — it lands in a review queue with a suggestion attached and
 * someone fixes it in a minute. A wrong match writes one station's price onto
 * another, reads as a perfectly plausible price, and nothing downstream can
 * detect it. So the matcher would rather miss than guess.
 *
 * Strategies, highest priority first:
 *
 *   1. Manual mapping — a reviewer already answered this exact question.
 *   2. Identifier — the listing carries a slug or id the platform stores.
 *   3. Company + address.
 *   4. Company + city + address.
 *   5. Fuzzy, gated on brand and city agreeing first.
 *
 * Confidence is returned with every answer and travels into
 * PriceService::recordPrice, which uses it to decide whether this reading may
 * supersede the stored one. That is what stops a fuzzy match overwriting an
 * operator's own price.
 */
final class StationMatcher
{
    /**
     * Manual mappings and directory candidates, held for the run.
     *
     * A national batch is thousands of records across hundreds of brands and
     * cities. Re-querying per record turns one import into tens of thousands of
     * queries; caching per company keeps it to one per brand.
     *
     * @var array<string, Collection<int, GasStation>>
     */
    private array $candidateCache = [];

    /** @var array<string, int>|null fingerprint → station id */
    private ?array $manualMappings = null;

    public function match(DoeRecord $record): StationMatch
    {
        // 1 — a reviewer already answered this. Nothing else is consulted,
        // because a human decision must not be re-litigated by a heuristic
        // that happens to score higher.
        $manual = $this->matchManualMapping($record);

        if ($manual !== null) {
            return new StationMatch($manual, 1.0, StationMatch::STRATEGY_MANUAL);
        }

        // 2 — an identifier, if the feed ever carries one. It does not today,
        // which is why this is cheap to check and worth keeping: the day the
        // DOE publishes station codes, this is where they plug in.
        $byIdentifier = $this->matchByIdentifier($record);

        if ($byIdentifier !== null) {
            return new StationMatch($byIdentifier, 1.0, StationMatch::STRATEGY_IDENTIFIER);
        }

        $candidates = $this->candidatesFor($record);

        if ($candidates->isEmpty()) {
            return StationMatch::miss();
        }

        // 3, 4 and 5 are scored over the same candidate set rather than run as
        // separate queries: the strategies differ in what they require to
        // agree, and scoring once lets the best-supported answer win.
        $best = null;
        $bestScore = 0.0;
        $bestStrategy = StationMatch::STRATEGY_NONE;

        foreach ($candidates as $candidate) {
            [$score, $strategy] = $this->score($record, $candidate);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
                $bestStrategy = $strategy;
            }
        }

        if ($best === null) {
            return StationMatch::miss();
        }

        return $bestScore >= StationMatch::ACCEPT_THRESHOLD
            ? new StationMatch($best, round($bestScore, 3), $bestStrategy)
            : StationMatch::miss($best, round($bestScore, 3), $bestStrategy);
    }

    // -- strategies ----------------------------------------------------------

    private function matchManualMapping(DoeRecord $record): ?GasStation
    {
        // A closure, not `intval(...)`: Collection::map passes the key as a
        // second argument, which intval reads as its numeric base.
        $this->manualMappings ??= DoeStationReview::query()
            ->mapped()
            ->pluck('resolved_station_id', 'fingerprint')
            ->map(static fn ($stationId): int => (int) $stationId)
            ->all();

        $stationId = $this->manualMappings[$record->fingerprint()] ?? null;

        return $stationId !== null ? GasStation::find($stationId) : null;
    }

    /**
     * An identifier embedded in the listing.
     *
     * The DOE does not publish station codes today. This is here for the day
     * it does — a feed carrying "petron-edsa-quezon-city" resolves exactly and
     * needs no scoring.
     *
     * The value must *already* be slug-shaped: lowercase, hyphenated, no
     * spaces. Slugifying a display name instead would make this fire on every
     * record, because `Str::slug('Petron EDSA')` equals the slug the directory
     * derived from the same words — and since an identifier hit skips scoring,
     * it would bypass the brand and city guards and match a Shell station in
     * another city.
     */
    private function matchByIdentifier(DoeRecord $record): ?GasStation
    {
        foreach (array_filter([$record->station, $record->address]) as $value) {
            $candidate = mb_strtolower(trim($value));

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/', $candidate) !== 1) {
                continue;
            }

            $station = GasStation::where('slug', $candidate)->first();

            if ($station !== null) {
                return $station;
            }
        }

        return null;
    }

    /**
     * Stations that could plausibly be this listing.
     *
     * Scoped by brand, because a comparison across the whole directory is both
     * slow and dangerous: "EDSA" appears in the name of stations belonging to
     * six different companies, and a name-only match picks whichever sorts
     * first.
     *
     * @return Collection<int, GasStation>
     */
    private function candidatesFor(DoeRecord $record): Collection
    {
        $company = trim((string) $record->company);

        if ($company === '') {
            return collect();
        }

        $key = mb_strtolower($company);

        // Both relations are eager loaded because the scorer reads both. City
        // decides whether a candidate is even eligible and brand is stripped
        // from the name before comparison, so lazy loading either would be an
        // N+1 across every candidate of every record in a national batch.
        return $this->candidateCache[$key] ??= GasStation::query()
            ->with(['city', 'brand'])
            ->whereHas('brand', fn ($brand) => $brand->where('name', 'like', '%'.$company.'%'))
            ->get();
    }

    /**
     * Score one candidate, and name the strategy that earned the score.
     *
     * @return array{0: float, 1: string}
     */
    private function score(DoeRecord $record, GasStation $candidate): array
    {
        $cityAgrees = $this->cityAgrees($record, $candidate);

        // Brand is already guaranteed by the candidate query. City disagreeing
        // is disqualifying rather than merely costly: two stations of the same
        // brand in different cities are different sites, however alike their
        // names, and "Petron EDSA" exists in several of them.
        if ($record->city !== null && ! $cityAgrees) {
            return [0.0, StationMatch::STRATEGY_NONE];
        }

        $nameScore = $this->similarity(
            $this->withoutBrand((string) $record->station, (string) $record->company),
            $this->withoutBrand((string) $candidate->name, (string) $candidate->brand?->name),
        );

        $addressScore = $record->address !== null && $candidate->address_line !== null
            ? $this->similarity($record->address, $candidate->address_line)
            : null;

        // Company + address, with the address carrying most of the weight:
        // within one brand it is the only thing that distinguishes sites.
        if ($addressScore !== null && $addressScore >= 0.85) {
            $score = 0.70 + (0.20 * $addressScore) + (0.10 * $nameScore);

            return [
                min(1.0, $score),
                $cityAgrees
                    ? StationMatch::STRATEGY_COMPANY_CITY_ADDRESS
                    : StationMatch::STRATEGY_COMPANY_ADDRESS,
            ];
        }

        // Company + city + address, where the address is suggestive rather
        // than conclusive.
        if ($cityAgrees && $addressScore !== null && $addressScore >= 0.55) {
            $score = 0.55 + (0.25 * $addressScore) + (0.20 * $nameScore);

            return [min(1.0, $score), StationMatch::STRATEGY_COMPANY_CITY_ADDRESS];
        }

        // Fuzzy: brand and city agree, and the names are close. Capped below 1
        // so a fuzzy match never claims the certainty of an exact one, and so
        // the confidence handed to PriceService keeps it from overwriting an
        // operator's price.
        $score = $cityAgrees
            ? 0.45 + (0.50 * $nameScore)
            : 0.20 * $nameScore;

        return [min(0.95, $score), StationMatch::STRATEGY_FUZZY];
    }

    // -- comparison ----------------------------------------------------------

    private function cityAgrees(DoeRecord $record, GasStation $candidate): bool
    {
        if ($record->city === null || $candidate->city === null) {
            return false;
        }

        $left = $this->normalize($record->city);
        $right = $this->normalize((string) $candidate->city->name);

        if ($left === '' || $right === '') {
            return false;
        }

        // Containment as well as equality: the DOE writes "Quezon City" where
        // the directory may hold "Quezon", and the reverse.
        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left);
    }

    /**
     * Strip a brand prefix from a station name.
     *
     * "Petron Shaw Boulevard" in the feed and "Shaw Boulevard" in the
     * directory are the same site written two ways. Comparing the full strings
     * scores them apart, while comparing raw names across brands scores every
     * "EDSA" station identical.
     */
    private function withoutBrand(string $name, string $brand): string
    {
        $name = $this->normalize($name);
        $brand = $this->normalize($brand);

        if ($brand !== '' && str_starts_with($name, $brand)) {
            $name = trim(substr($name, strlen($brand)));
        }

        return $name !== '' ? $name : $this->normalize($name);
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $cleaned = preg_replace('/[^a-z0-9 ]+/', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');
    }

    /**
     * Similarity between two strings, 0 to 1.
     *
     * `similar_text` rather than `levenshtein`: the latter is capped at 255
     * bytes and returns -1 beyond it, which for an address field silently
     * turns a long address into a negative score. It is also O(n³) worst case,
     * so inputs are bounded first.
     */
    private function similarity(string $left, string $right): float
    {
        $left = mb_substr($this->normalize($left), 0, 120);
        $right = mb_substr($this->normalize($right), 0, 120);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percent);

        return round($percent / 100, 4);
    }

    /**
     * Forget cached candidates and manual mappings.
     *
     * Called between batches: a reviewer resolving a listing mid-run should
     * take effect on the next batch, not the next deploy.
     */
    public function flush(): void
    {
        $this->candidateCache = [];
        $this->manualMappings = null;
    }
}
