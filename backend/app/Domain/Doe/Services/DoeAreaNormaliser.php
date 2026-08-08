<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

/**
 * One place-name normaliser, applied symmetrically to both sides.
 *
 * The DOE's area labels and the platform's city names describe the same
 * places in different house styles. Comparing them raw fails; comparing them
 * with a rule applied to only one side fails *silently and asymmetrically*,
 * which is worse — normalising only the DOE label reported all five Quezon
 * City stations as unmatched, because the platform's own name already ends in
 * "City" and the DOE's had just had it removed.
 *
 * Every rule below is exact and reversible in intent. There is deliberately
 * no fuzzy matching, no Levenshtein, no LIKE '%area%': those turn a wrong
 * match into a plausible one, and a plausible wrong match is how a Cebu price
 * ends up on a Pampanga forecourt.
 */
final class DoeAreaNormaliser
{
    /**
     * The rules, in order:
     *
     *  1. Unicode diacritics are folded — "Parañaque" and "Paranaque" are the
     *     same municipality, and the two datasets are not consistent about it.
     *     Deterministic, not fuzzy: one character maps to one character.
     *  2. Case is folded to lower.
     *  3. Runs of whitespace collapse to a single space, and the ends trim.
     *  4. A trailing "City" is dropped. Both sides use it inconsistently:
     *     the DOE writes "Makati City" where the platform writes "Makati",
     *     and writes "Quezon City" where the platform also writes "Quezon
     *     City".
     *  5. A trailing "Cty" is dropped for the same reason. This is not a
     *     general typo tolerance — it is one specific misspelling the DOE
     *     prints in its NCR report, as "Taguig Cty". Anything broader would
     *     be fuzzy matching by another name.
     */
    public function normalise(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $folded = $this->foldDiacritics($value);
        $lowered = mb_strtolower($folded, 'UTF-8');
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $lowered));

        return (string) preg_replace('/\s+(city|cty)$/u', '', $collapsed);
    }

    /** True when two place names denote the same place under the rules above. */
    public function matches(?string $left, ?string $right): bool
    {
        $a = $this->normalise($left);
        $b = $this->normalise($right);

        // Two blanks are not a match. An unknown place does not equal another
        // unknown place, and treating them as equal would map every station
        // with a missing city onto every area with a missing name.
        return $a !== '' && $a === $b;
    }

    private function foldDiacritics(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($transliterated === false) {
            return $value;
        }

        // iconv renders some characters as "n" and others as "'n" or "~n"
        // depending on the platform's locale data, so the combining marks it
        // leaves behind are stripped rather than trusted.
        return (string) preg_replace('/[^\x20-\x7E]/', '', str_replace(['\'', '`', '^', '~', '"'], '', $transliterated));
    }
}
