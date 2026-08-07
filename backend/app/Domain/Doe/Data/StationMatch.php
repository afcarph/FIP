<?php

declare(strict_types=1);

namespace App\Domain\Doe\Data;

use App\Domain\Station\Models\GasStation;

/**
 * The matcher's answer: a station, how it was found, and how sure it is.
 *
 * A miss is still a result, not a null — it carries the best candidate the
 * matcher rejected, which is what makes the review queue quick to work
 * through. Confirming a 0.72 suggestion is far less work than searching the
 * directory from scratch.
 */
final readonly class StationMatch
{
    /** A reviewer mapped this listing by hand. Nothing outranks it. */
    public const STRATEGY_MANUAL = 'manual';

    /** The DOE listing carried an identifier the platform already stores. */
    public const STRATEGY_IDENTIFIER = 'identifier';

    public const STRATEGY_COMPANY_ADDRESS = 'company_address';

    public const STRATEGY_COMPANY_CITY_ADDRESS = 'company_city_address';

    public const STRATEGY_FUZZY = 'fuzzy';

    public const STRATEGY_NONE = 'none';

    /**
     * Below this a match is not used, and the listing goes to review.
     *
     * Set where it is because the cost is asymmetric. An unmatched station is
     * visible — it sits in a queue with a suggestion attached. A wrong match
     * writes one station's price onto another, reads as an entirely plausible
     * price, and nothing downstream can tell.
     */
    public const ACCEPT_THRESHOLD = 0.75;

    public function __construct(
        public ?GasStation $station,
        public float $confidence,
        public string $strategy,
        public ?GasStation $candidate = null,
    ) {}

    public static function miss(?GasStation $candidate = null, float $confidence = 0.0, string $strategy = self::STRATEGY_NONE): self
    {
        return new self(null, $confidence, $strategy, $candidate);
    }

    public function matched(): bool
    {
        return $this->station !== null;
    }

    /**
     * The station to suggest in the review queue — whichever the matcher liked
     * best, accepted or not.
     */
    public function bestCandidate(): ?GasStation
    {
        return $this->station ?? $this->candidate;
    }
}
