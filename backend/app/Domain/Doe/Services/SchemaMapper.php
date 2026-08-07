<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Support\LookerSchema;

/**
 * Resolves Looker's generated field ids to the platform's own vocabulary.
 *
 * Two levels of indirection, neither of them hardcoded:
 *
 *     qt_85e4fhiemc  ──getSchema──▶  "Gas Station"   ──patterns──▶  station
 *     qt_p7q8r9siemc ──getSchema──▶  "RON 95"        ──patterns──▶  gasoline_ron95
 *     (per report)                   (published)                    (fuel_types.code)
 *
 * The ids change whenever the DOE edits the report. The labels are what the
 * DOE publishes to the public, which makes them the stable half of the pair —
 * and the fuel side resolves to `fuel_types.code`, so the mapping lands
 * directly in the platform's existing model rather than a parallel one.
 */
final class SchemaMapper
{
    /** @var array<string, string> internal id → published label */
    private array $labels = [];

    /** @var array<string, string> internal id → record key or fuel code */
    private array $columns = [];

    /** @var array<string, string> internal id → 'fuel'|'dimension' */
    private array $kinds = [];

    /** @var list<string> labels seen but not recognised */
    private array $unmapped = [];

    /**
     * Build the mapping from every getSchema payload in a batch.
     *
     * Payloads are merged because Looker requests a schema per data source: a
     * dashboard whose map tile has its own source returns two.
     *
     * @param list<mixed> $schemaPayloads
     */
    public function build(array $schemaPayloads): self
    {
        foreach ($schemaPayloads as $payload) {
            foreach ($this->extractLabels($payload) as $fieldId => $label) {
                $this->labels[$fieldId] ??= $label;
            }
        }

        $taken = [];

        foreach ($this->labels as $fieldId => $label) {
            [$column, $kind] = $this->classify($label);

            if ($column === null) {
                $this->unmapped[] = $label;

                continue;
            }

            // Two ids claiming one column happens when a report carries both a
            // "Diesel" metric and a "Diesel (avg)" calculated field. Keep the
            // first and record the loser, rather than letting array order
            // decide which field the prices come from.
            if (isset($taken[$column])) {
                $this->unmapped[] = "{$label} (duplicate of {$taken[$column]})";

                continue;
            }

            $taken[$column] = $label;
            $this->columns[$fieldId] = $column;
            $this->kinds[$fieldId] = $kind;
        }

        return $this;
    }

    /**
     * Walk a payload for internal id → label pairs.
     *
     * Walked rather than indexed: Looker nests the schema differently between
     * report versions — under `datasourceSchema`, `dataset.fields`, or a bare
     * `fields` — and a fixed path yields nothing instead of failing when it
     * moves.
     *
     * @return array<string, string>
     */
    public function extractLabels(mixed $payload): array
    {
        $labels = [];

        foreach ($this->walkObjects($payload) as $node) {
            $fieldId = null;

            foreach (LookerSchema::ID_KEYS as $key) {
                if (LookerSchema::looksLikeFieldId($node[$key] ?? null)) {
                    $fieldId = $node[$key];
                    break;
                }
            }

            if ($fieldId === null) {
                continue;
            }

            foreach (LookerSchema::LABEL_KEYS as $key) {
                $candidate = $node[$key] ?? null;

                // The label must differ from the id, or a node whose only
                // strings are both the id maps onto itself and the id starts
                // looking like a resolved label in the logs.
                if (is_string($candidate) && trim($candidate) !== '' && $candidate !== $fieldId) {
                    $labels[$fieldId] ??= trim($candidate);
                    break;
                }
            }
        }

        return $labels;
    }

    /**
     * Map one published label to a fuel code or a record key.
     *
     * Fuel patterns first: a column labelled "Diesel Price" is a price, and
     * would otherwise be caught by a dimension pattern in some report
     * revisions.
     *
     * @return array{0: string|null, 1: string}
     */
    public function classify(string $label): array
    {
        $normalized = LookerSchema::normalizeLabel($label);

        if ($normalized === '') {
            return [null, 'unknown'];
        }

        foreach (LookerSchema::FUEL_PATTERNS as $pattern => $code) {
            if (preg_match($pattern, $normalized) === 1) {
                return [$code, 'fuel'];
            }
        }

        foreach (LookerSchema::DIMENSION_PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $normalized) === 1) {
                return [$key, 'dimension'];
            }
        }

        return [null, 'unknown'];
    }

    /** @return iterable<array<string, mixed>> */
    private function walkObjects(mixed $node): iterable
    {
        if (is_array($node)) {
            if (! array_is_list($node)) {
                yield $node;
            }

            foreach ($node as $value) {
                yield from $this->walkObjects($value);
            }
        }
    }

    public function columnFor(string $fieldId): ?string
    {
        return $this->columns[$fieldId] ?? null;
    }

    public function isFuel(string $fieldId): bool
    {
        return ($this->kinds[$fieldId] ?? null) === 'fuel';
    }

    public function labelFor(string $fieldId): string
    {
        return $this->labels[$fieldId] ?? $fieldId;
    }

    /**
     * Whether enough was resolved to produce a usable record.
     *
     * A mapping with a station but no fuel would import rows of nothing; one
     * with fuels but no station cannot attribute them. Either way the run
     * should fail rather than report success having written nothing.
     */
    public function isUsable(): bool
    {
        $mapped = array_values($this->columns);

        return in_array('station', $mapped, true)
            && in_array('fuel', array_values($this->kinds), true);
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<string> */
    public function unmapped(): array
    {
        return array_values(array_unique($this->unmapped));
    }

    /** @return list<string> the fuel codes this payload carries */
    public function fuelCodes(): array
    {
        $codes = [];

        foreach ($this->columns as $fieldId => $column) {
            if ($this->isFuel($fieldId)) {
                $codes[] = $column;
            }
        }

        return array_values(array_unique($codes));
    }

    public function summary(): string
    {
        $mapped = array_values(array_unique(array_values($this->columns)));
        sort($mapped);

        return sprintf('mapped=[%s] unmapped=%d', implode(', ', $mapped), count($this->unmapped()));
    }
}
