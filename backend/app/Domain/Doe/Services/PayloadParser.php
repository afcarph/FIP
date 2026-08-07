<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Data\DoeRecord;
use App\Domain\Doe\Support\LookerSchema;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Turns a stored `batchedDataV2` payload into records.
 *
 * Looker does not send rows. It sends parallel, typed column arrays plus a
 * positional null mask, and a "row" is an index across all of them. Two things
 * follow:
 *
 *  - **The null mask is not optional.** A column with nulls carries *fewer*
 *    values than the row count, because Looker omits them rather than sending
 *    null entries. Pairing values with rows by position without re-inserting
 *    the gaps shifts every subsequent value up a row — which produces a
 *    complete, plausible, entirely wrong dataset. Nothing downstream can
 *    detect it, which is why {@see expandNulls} has tests of its own.
 *
 *  - **Column identity is not in the dataset.** The field ids sit beside it in
 *    the request echo and have to be found.
 *
 * This lives in PHP rather than in the scraper so a parser fix can be replayed
 * against payloads already captured. If the scraper parsed, correcting a bug
 * would need the DOE data for that day, and by then the dashboard shows a
 * different week.
 */
final class PayloadParser
{
    /** @var list<string> */
    private array $errors = [];

    private int $datasetsSeen = 0;

    /**
     * Parse every dataset in every payload.
     *
     * One unusable dataset does not stop the rest: a dashboard page carries
     * several tiles, and the summary tiles routinely have a shape we cannot
     * use, which must not cost us the detail table.
     *
     * @param list<mixed> $dataPayloads
     * @return list<DoeRecord>
     */
    public function parse(array $dataPayloads, SchemaMapper $mapper): array
    {
        $this->errors = [];
        $this->datasetsSeen = 0;

        $records = [];

        foreach ($dataPayloads as $index => $payload) {
            foreach ($this->findDatasets($payload) as [$dataset, $ancestors]) {
                $this->datasetsSeen++;

                try {
                    array_push($records, ...$this->parseDataset($dataset, $ancestors, $mapper));
                } catch (RuntimeException $exception) {
                    $this->errors[] = "payload {$index}: {$exception->getMessage()}";
                }
            }
        }

        return $this->deduplicate($records);
    }

    /**
     * Yield `[tableDataset, ancestors]` for every dataset in a payload.
     *
     * Ancestors come back nearest-first so the field-id search starts close to
     * the dataset and widens, rather than picking up ids belonging to another
     * tile on the same page.
     *
     * @return iterable<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>
     */
    private function findDatasets(mixed $node, array $ancestors = []): iterable
    {
        if (! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                yield from $this->findDatasets($item, $ancestors);
            }

            return;
        }

        if (isset($node['tableDataset']) && is_array($node['tableDataset'])) {
            yield [$node['tableDataset'], [$node, ...$ancestors]];
        }

        foreach ($node as $key => $value) {
            if ($key === 'tableDataset') {
                continue;
            }

            yield from $this->findDatasets($value, [$node, ...$ancestors]);
        }
    }

    /**
     * @param array<string, mixed> $dataset
     * @param list<array<string, mixed>> $ancestors
     * @return list<DoeRecord>
     */
    private function parseDataset(array $dataset, array $ancestors, SchemaMapper $mapper): array
    {
        $entries = $this->columnEntries($dataset);

        if ($entries === []) {
            return [];
        }

        $columns = array_map($this->typedValues(...), $entries);
        $rowCount = $this->rowCount($columns);

        if ($rowCount === 0) {
            return [];
        }

        $fieldIds = $this->findFieldIds($ancestors, count($columns));

        if ($fieldIds === null) {
            // Content could identify the dimensions, but never which grade a
            // price column is. A dataset whose RON 91 and RON 95 might be
            // swapped is worse than no dataset, because it looks fine.
            throw new RuntimeException(sprintf(
                'no field ids for a %d-column dataset; cannot attribute columns',
                count($columns),
            ));
        }

        $expanded = [];
        foreach ($columns as $position => [$values, $nulls]) {
            $expanded[$position] = $this->expandNulls($values, $nulls, $rowCount);
        }

        $records = [];

        for ($row = 0; $row < $rowCount; $row++) {
            $raw = [];
            $prices = [];

            foreach ($fieldIds as $position => $fieldId) {
                $column = $mapper->columnFor($fieldId);

                if ($column === null) {
                    continue;
                }

                $value = $expanded[$position][$row] ?? null;

                if ($mapper->isFuel($fieldId)) {
                    $prices[$column] = $value;
                } else {
                    $raw[$column] = $value;
                }
            }

            $record = $this->buildRecord($raw, $prices);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Re-insert the values Looker omitted, at the positions it recorded.
     *
     * `$nullIndexes` are positions in the *final* column. Walking the target
     * positions in order and drawing from `$values` only where the position is
     * not masked reconstructs the original alignment.
     *
     * @param list<mixed> $values
     * @param list<int> $nullIndexes
     * @return list<mixed>
     */
    public function expandNulls(array $values, array $nullIndexes, int $rowCount): array
    {
        if ($nullIndexes === []) {
            return $values;
        }

        $masked = array_flip($nullIndexes);
        $expanded = [];
        $cursor = 0;

        for ($position = 0; $position < $rowCount; $position++) {
            if (isset($masked[$position])) {
                $expanded[] = null;

                continue;
            }

            $expanded[] = $values[$cursor] ?? null;
            $cursor++;
        }

        return $expanded;
    }

    /**
     * The per-column wrappers, in order. Looker has used both keys.
     *
     * @param array<string, mixed> $dataset
     * @return list<array<string, mixed>>
     */
    private function columnEntries(array $dataset): array
    {
        foreach (['column', 'columns'] as $key) {
            if (isset($dataset[$key]) && is_array($dataset[$key])) {
                return array_values(array_filter($dataset[$key], is_array(...)));
            }
        }

        return [];
    }

    /**
     * Values and null positions from one typed column wrapper.
     *
     * @param array<string, mixed> $entry
     * @return array{0: list<mixed>, 1: list<int>}
     */
    private function typedValues(array $entry): array
    {
        foreach (LookerSchema::COLUMN_CONTAINERS as $containerKey) {
            $container = $entry[$containerKey] ?? null;

            if (! is_array($container)) {
                continue;
            }

            $values = [];
            foreach (['values', 'value', 'nanos'] as $valueKey) {
                if (isset($container[$valueKey]) && is_array($container[$valueKey])) {
                    $values = array_values($container[$valueKey]);
                    break;
                }
            }

            $rawNulls = $container[LookerSchema::NULL_INDEX_KEY] ?? [];
            $nulls = is_array($rawNulls)
                ? array_values(array_map(intval(...), array_filter($rawNulls, is_numeric(...))))
                : [];

            return [$values, $nulls];
        }

        // A wrapper that is itself a bare list, which older responses used.
        foreach ($entry as $value) {
            if (is_array($value) && array_is_list($value)) {
                return [$value, []];
            }
        }

        return [[], []];
    }

    /**
     * A column's true length is its values plus its nulls. The maximum across
     * columns tolerates a trailing column Looker truncated, which it does when
     * a tile is still rendering.
     *
     * @param list<array{0: list<mixed>, 1: list<int>}> $columns
     */
    private function rowCount(array $columns): int
    {
        $lengths = array_map(
            static fn (array $column): int => count($column[0]) + count($column[1]),
            $columns,
        );

        return $lengths === [] ? 0 : max($lengths);
    }

    /**
     * Locate the ordered field ids for a dataset.
     *
     * Accepted only when the count matches the column count: a list of the
     * right shape but the wrong length belongs to another tile, and using it
     * would label every column wrongly.
     *
     * @param list<array<string, mixed>> $ancestors
     * @return list<string>|null
     */
    private function findFieldIds(array $ancestors, int $expected): ?array
    {
        foreach ($ancestors as $ancestor) {
            $found = $this->searchIdList($ancestor, $expected, 0);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function searchIdList(mixed $node, int $expected, int $depth): ?array
    {
        if ($depth > 6 || ! is_array($node) || $expected < 1) {
            return null;
        }

        if (array_is_list($node)) {
            if (count($node) === $expected) {
                $ids = array_map($this->idOf(...), $node);

                if (! in_array(null, $ids, true)) {
                    /** @var list<string> $ids */
                    return $ids;
                }
            }

            foreach ($node as $item) {
                $found = $this->searchIdList($item, $expected, $depth + 1);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        foreach ($node as $key => $value) {
            if ($key === 'tableDataset') {
                continue;
            }

            $found = $this->searchIdList($value, $expected, $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function idOf(mixed $item): ?string
    {
        if (LookerSchema::looksLikeFieldId($item)) {
            return $item;
        }

        if (is_array($item)) {
            foreach (LookerSchema::ID_KEYS as $key) {
                if (LookerSchema::looksLikeFieldId($item[$key] ?? null)) {
                    return $item[$key];
                }
            }
        }

        return null;
    }

    /**
     * Coerce one mapped row into a record, or drop it.
     *
     * Values are only shaped here, never judged. Whether a price is plausible
     * is PriceService's call and is not second-guessed; what this rejects is a
     * row that cannot be a record at all — no station, no date, or no prices.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $prices
     */
    private function buildRecord(array $raw, array $prices): ?DoeRecord
    {
        $station = $this->text($raw['station'] ?? null, 200);
        $date = $this->date($raw['price_date'] ?? null);

        if ($station === null || $date === null) {
            return null;
        }

        $numeric = [];
        foreach ($prices as $code => $value) {
            $price = $this->number($value);

            if ($price !== null) {
                $numeric[$code] = $price;
            }
        }

        // A row with no prices is not a partial record; storing it would put a
        // hole in the series that looks like a station that stopped selling.
        if ($numeric === []) {
            return null;
        }

        return new DoeRecord(
            company: $this->text($raw['company'] ?? null, 160),
            station: $station,
            address: $this->text($raw['address'] ?? null, 400),
            city: $this->text($raw['city'] ?? null, 160),
            province: $this->text($raw['province'] ?? null, 160),
            barangay: $this->text($raw['barangay'] ?? null, 160),
            latitude: $this->number($raw['latitude'] ?? null),
            longitude: $this->number($raw['longitude'] ?? null),
            priceDate: $date,
            prices: $numeric,
        );
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (LookerSchema::isNullToken($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function number(mixed $value): ?float
    {
        if (LookerSchema::isNullToken($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', '₱', 'P', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (LookerSchema::isNullToken($value)) {
            return null;
        }

        // Epoch values. Looker sends seconds for date fields and milliseconds
        // for datetimes; the threshold separates them unambiguously for any
        // date this century.
        if (is_int($value) || is_float($value)) {
            $seconds = $value > 10_000_000_000 ? $value / 1000 : $value;

            return Carbon::createFromTimestampUTC((int) $seconds)->startOfDay();
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        foreach (LookerSchema::DATE_FORMATS as $format) {
            try {
                // Carbon's strict mode throws rather than returning false, so
                // trying nine formats in turn means catching eight of them.
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (InvalidFormatException) {
                continue;
            }

            // Round-tripped, because a lenient format accepts input it then
            // reinterprets: 'Ymd' parses "2026-08-06" into the year 2026 with
            // a nonsense month, and reports success.
            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed->startOfDay();
            }
        }

        if (ctype_digit($text) && strlen($text) >= 10) {
            $number = (int) $text;
            $seconds = $number > 10_000_000_000 ? intdiv($number, 1000) : $number;

            return Carbon::createFromTimestampUTC($seconds)->startOfDay();
        }

        return null;
    }

    /**
     * Collapse rows describing the same station and date.
     *
     * @param list<DoeRecord> $records
     * @return list<DoeRecord>
     */
    private function deduplicate(array $records): array
    {
        $merged = [];

        foreach ($records as $record) {
            $key = $record->fingerprint().'|'.$record->priceDate->toDateString();

            $merged[$key] = isset($merged[$key])
                ? $merged[$key]->mergedWith($record)
                : $record;
        }

        return array_values($merged);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function datasetsSeen(): int
    {
        return $this->datasetsSeen;
    }
}
