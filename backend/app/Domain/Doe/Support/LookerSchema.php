<?php

declare(strict_types=1);

namespace App\Domain\Doe\Support;

/**
 * What is true about the DOE's Looker Studio dashboard.
 *
 * Two things this class is careful *not* to hold.
 *
 * It holds no internal field ids. Looker names fields `qt_fgaojmiemc` and
 * reissues those ids every time the report is edited, so an importer pinned to
 * them does not fail loudly when the DOE republishes — it keeps running and
 * imports nothing. The ids are resolved per payload from `getSchema`; what
 * lives here is how to recognise a *published* label, which is stable because
 * it is what the DOE shows the public.
 *
 * It holds no HTML. There is no selector, no xpath and no table layout in this
 * codebase; the JSON payload is the only source.
 */
final class LookerSchema
{
    /** Looker's anti-JSON-hijacking guard, which precedes every body. */
    public const JSON_GUARD = ")]}'";

    public const DATA_ENDPOINT = 'batchedDataV2';

    public const SCHEMA_ENDPOINT = 'getSchema';

    public const REPORT_ENDPOINT = 'getReport';

    /** Within a dataset, the per-type column arrays. */
    public const COLUMN_CONTAINERS = [
        'stringColumn',
        'doubleColumn',
        'longColumn',
        'dateColumn',
        'datetimeColumn',
        'boolColumn',
        'bytesColumn',
    ];

    /**
     * Positions Looker omitted from a column's values.
     *
     * The single most important key in the payload. Looker does not send null
     * entries; it sends fewer values and records where the gaps were. Pairing
     * values with rows by position without re-inserting them shifts every later
     * value up a row, producing a complete, plausible, entirely wrong dataset
     * that nothing downstream can detect.
     */
    public const NULL_INDEX_KEY = 'nullIndex';

    /** Keys that carry a field's internal id. */
    public const ID_KEYS = ['name', 'id', 'fieldId', 'lookerFieldId', 'dataSourceFieldId'];

    /** Keys that carry a field's published label. */
    public const LABEL_KEYS = ['label', 'displayName', 'alias', 'title', 'caption'];

    /** What a generated Looker field id looks like. */
    public const FIELD_ID_PATTERN = '/^(?:qt_|_n_|calc_)?[a-z0-9_]{6,64}$/i';

    /**
     * Published label to the platform's fuel type code.
     *
     * The values are `fuel_types.code` — the platform's own vocabulary, not a
     * parallel one. A grade the platform does not sell (the DOE lists RON 100)
     * is deliberately absent: mapping it onto RON 97 would file one product's
     * price under another, which reads as plausible and is undetectable later.
     * Those records are reported as skipped instead.
     *
     * Order matters. `diesel_premium` is tested before `diesel` because "Diesel
     * Plus" contains "Diesel" and would otherwise be swallowed by it.
     *
     * @var array<string, string>
     */
    public const FUEL_PATTERNS = [
        // Both orders: the DOE writes "Diesel Plus", retailers write "Euro 5
        // Diesel", and a one-directional pattern files the latter as plain
        // diesel — two different products in one column.
        '/(?:diesel\s*(?:plus|premium|euro\s*\d|max|blaze|xtra|extra)|(?:premium|euro\s*\d|max|blaze|xtra|extra)\s*diesel)/i' => 'diesel_premium',
        '/\bdiesel\b/i' => 'diesel',
        '/\bkerosene\b/i' => 'kerosene',
        '/\b(?:auto\s*)?lpg\b/i' => 'lpg_auto',
        // RON 97 before RON 95 before RON 91 is not significant, but 100 must
        // precede them so a future grade is not partially matched.
        '/(?:ron)?\s*100\b/i' => 'gasoline_ron100',
        '/(?:ron)?\s*97\b/i' => 'gasoline_ron97',
        '/(?:ron)?\s*95\b/i' => 'gasoline_ron95',
        '/(?:ron)?\s*91\b/i' => 'gasoline_ron91',
    ];

    /**
     * Published label to a descriptive field on the record.
     *
     * @var array<string, string>
     */
    public const DIMENSION_PATTERNS = [
        '/^\s*lat(?:itude)?\s*$/i' => 'latitude',
        '/^\s*(?:lon|lng|long(?:itude)?)\s*$/i' => 'longitude',
        '/\b(?:timestamp|date|as\s*of|effectiv|updated|week)\b/i' => 'price_date',
        // No trailing \b after the stem: "compan" anchored would fail on every
        // real spelling of the word.
        '/\b(?:compan(?:y|ies)|brand|oil\s*(?:firm|compan\w*)|retailer|player)/i' => 'company',
        '/\b(?:gas\s*station|station|outlet|site|branch)\b/i' => 'station',
        '/\b(?:barangay|brgy)\b/i' => 'barangay',
        '/\b(?:city|municipalit(?:y|ies))/i' => 'city',
        '/\bprovince\b/i' => 'province',
        '/\bregion\b/i' => 'region',
        '/\b(?:address|location|street)\b/i' => 'address',
    ];

    /** Values the feed uses for "nothing here". */
    public const NULL_TOKENS = ['', '-', '--', 'n/a', 'na', 'null', 'none', 'nil', '#n/a', 'no data', 'tbd'];

    /** Date formats report authors have configured. */
    public const DATE_FORMATS = [
        'Ymd',
        'Y-m-d',
        'Y/m/d',
        'd/m/Y',
        'm/d/Y',
        'YmdH',
        'YmdHis',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
    ];

    /**
     * Collapse a published label to a comparable form.
     *
     * "+" is spelled out before punctuation is stripped: "Diesel+" is a
     * different product from "Diesel", and dropping the sign as punctuation
     * files the two into one column.
     */
    public static function normalizeLabel(string $label): string
    {
        $spelled = str_replace('+', ' plus ', mb_strtolower(trim($label)));
        $cleaned = preg_replace('/[^a-z0-9]+/', ' ', $spelled) ?? '';

        return trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');
    }

    public static function isNullToken(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), self::NULL_TOKENS, true);
        }

        return false;
    }

    public static function looksLikeFieldId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FIELD_ID_PATTERN, $value) === 1;
    }

    /**
     * Strip Looker's guard so the body will decode.
     *
     * The guard makes a `<script src>` pointing at the endpoint throw rather
     * than leak; for us it is four bytes that must come off first.
     */
    public static function stripGuard(string $body): string
    {
        // The BOM first: a proxy that re-encodes the body leaves the guard no
        // longer at byte zero, and json_decode fails on a character that is not
        // visible in any error message.
        $trimmed = ltrim($body, "\u{FEFF} \t\r\n");

        if (str_starts_with($trimmed, self::JSON_GUARD)) {
            $trimmed = substr($trimmed, strlen(self::JSON_GUARD));
        }

        return ltrim($trimmed, "\r\n");
    }
}
