<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Data\DoeRecord;
use App\Domain\Doe\Data\PriceData;
use App\Domain\Doe\Data\StationMatch;
use App\Domain\Doe\Models\DoeImportBatch;
use App\Domain\Doe\Models\DoeStationReview;
use App\Domain\Doe\Support\LookerSchema;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Services\PriceService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Imports a captured batch into the platform's price model.
 *
 *     stored payload → schema mapper → parser → validator → matcher
 *                    → PriceData → PriceService::recordPrice()
 *
 * Everything after the DTO is the platform's existing machinery and is not
 * reimplemented here. In particular:
 *
 *  - **Plausibility** is PriceService's, which throws on an absurd price.
 *  - **Precedence** is PriceService's: it decides whether a reading may
 *    supersede the stored one, which is why the matcher's confidence is passed
 *    through rather than discarded.
 *  - **History** is PriceService's — it writes `fuel_price_history` in the same
 *    transaction as `station_prices`.
 *  - **Idempotency** follows from the above. Re-importing a batch replays the
 *    same readings at the same effective time; PriceService recognises they do
 *    not supersede what is stored and no duplicate is created.
 */
final class DoeImportService
{
    public function __construct(
        private readonly PriceService $prices,
        private readonly StationMatcher $matcher,
        private readonly PayloadParser $parser,
    ) {}

    /**
     * Import one batch. Never throws — the outcome is on the batch.
     *
     * A command working through a queue of batches must not stop because one
     * of them is corrupt, and a batch that fails has to record *why* or it
     * cannot be triaged later.
     */
    public function import(DoeImportBatch $batch): ImportOutcome
    {
        $batch->update(['status' => DoeImportBatch::STATUS_IMPORTING]);
        $this->matcher->flush();

        try {
            $outcome = $this->run($batch);
        } catch (Throwable $exception) {
            $batch->markFailed($exception->getMessage());
            $batch->log('Import failed: '.$exception->getMessage(), 'error', [
                'exception' => $exception::class,
            ]);

            return new ImportOutcome(failed: true, error: $exception->getMessage());
        }

        $batch->update([
            'records_parsed' => $outcome->parsed,
            'records_imported' => $outcome->imported,
            'records_skipped' => $outcome->skipped,
            'stations_unmatched' => $outcome->unmatched,
        ]);
        $batch->markImported();

        return $outcome;
    }

    private function run(DoeImportBatch $batch): ImportOutcome
    {
        $payload = $batch->decodedPayload();

        if ($payload === null) {
            throw new DomainException('The stored payload is missing or will not decode.');
        }

        $mapper = (new SchemaMapper)->build($this->payloadsOf($payload, LookerSchema::SCHEMA_ENDPOINT));

        if ($mapper->labels() === []) {
            // getReport carries the same field definitions and is a usable
            // fallback when the schema call was served from the browser cache.
            $batch->log('No getSchema in the payload; falling back to getReport.', 'warning');
            $mapper = (new SchemaMapper)->build($this->payloadsOf($payload, LookerSchema::REPORT_ENDPOINT));
        }

        if (! $mapper->isUsable()) {
            throw new DomainException(
                'Field mapping is unusable — no station or no fuel column was recognised '
                ."({$mapper->summary()}). The dashboard's labels have probably changed.",
            );
        }

        $batch->log("Schema mapped: {$mapper->summary()}", 'info', [
            'unmapped' => $mapper->unmapped(),
            'fuels' => $mapper->fuelCodes(),
        ]);

        $records = $this->parser->parse($this->payloadsOf($payload, LookerSchema::DATA_ENDPOINT), $mapper);

        foreach ($this->parser->errors() as $error) {
            $batch->log($error, 'warning');
        }

        if ($records === []) {
            throw new DomainException(sprintf(
                'The payload yielded no records from %d datasets.',
                $this->parser->datasetsSeen(),
            ));
        }

        return $this->importRecords($batch, $records);
    }

    /**
     * @param list<DoeRecord> $records
     */
    private function importRecords(DoeImportBatch $batch, array $records): ImportOutcome
    {
        $fuelTypes = $this->fuelTypes();
        $outcome = new ImportOutcome(parsed: count($records));

        foreach ($records as $record) {
            $match = $this->matcher->match($record);

            if (! $match->matched()) {
                $this->queueForReview($record, $match);
                $outcome->unmatched++;
                $outcome->skipped += count($record->prices);

                continue;
            }

            foreach ($record->prices as $fuelCode => $price) {
                $fuelType = $fuelTypes->get($fuelCode);

                if ($fuelType === null) {
                    // A grade the platform does not sell — the DOE lists RON
                    // 100. Skipped rather than mapped onto a neighbouring
                    // grade, which would file one product's price under
                    // another and read as entirely plausible.
                    $outcome->skipped++;
                    $outcome->unknownFuels[$fuelCode] = true;

                    continue;
                }

                $data = new PriceData(
                    station: $match->station,
                    fuelTypeId: (int) $fuelType->getKey(),
                    fuelCode: $fuelCode,
                    price: $price,
                    // 06:00 local, when the DOE's adjustments take effect. The
                    // time matters: PriceService compares it against the stored
                    // reading to decide precedence.
                    effectiveAt: $record->priceDate->copy()->setTime(6, 0),
                    confidence: $match->confidence,
                );

                try {
                    $this->prices->recordPrice(
                        station: $data->station,
                        fuelTypeId: $data->fuelTypeId,
                        price: $data->price,
                        source: $data->source,
                        confidence: $data->confidence,
                        effectiveAt: $data->effectiveAt,
                    );

                    $outcome->imported++;
                } catch (DomainException $exception) {
                    // PriceService rejected it — an implausible price. Its
                    // judgement, not ours, and one bad reading must not cost
                    // the rest of a national feed.
                    $outcome->skipped++;
                    $outcome->rejected[] = $record->describe().": {$exception->getMessage()}";
                }
            }
        }

        $this->summarise($batch, $outcome);

        return $outcome;
    }

    /**
     * Record an unmatched listing for a human, or bump the one already there.
     *
     * Keyed on the listing's fingerprint so a station that fails to match every
     * day for a month is one queue item with `times_seen = 30`, not thirty
     * items. A queue that grows by thousands a week does not get worked.
     */
    private function queueForReview(DoeRecord $record, StationMatch $match): void
    {
        $candidate = $match->bestCandidate();
        $fingerprint = $record->fingerprint();

        $existing = DoeStationReview::where('fingerprint', $fingerprint)->first();

        $attributes = [
            'doe_company' => $record->company,
            'doe_station' => $record->station,
            'doe_address' => $record->address,
            'doe_city' => $record->city,
            'doe_province' => $record->province,
            'doe_barangay' => $record->barangay,
            'doe_latitude' => $record->latitude,
            'doe_longitude' => $record->longitude,
            'suggested_station_id' => $candidate?->getKey(),
            'suggested_confidence' => $match->confidence > 0 ? $match->confidence : null,
            'suggested_strategy' => $match->strategy,
            'last_seen_at' => now(),
        ];

        if ($existing === null) {
            // The row's own default is 1, which is this first sighting. An
            // upsert-then-increment would count it twice and make every
            // triage figure one too high.
            DoeStationReview::create($attributes + ['fingerprint' => $fingerprint]);

            return;
        }

        $existing->update($attributes);
        $existing->increment('times_seen');
    }

    private function summarise(DoeImportBatch $batch, ImportOutcome $outcome): void
    {
        $batch->log($outcome->summary(), 'info');

        if ($outcome->unknownFuels !== []) {
            $batch->log(
                'Fuel grades with no platform fuel type: '.implode(', ', array_keys($outcome->unknownFuels)),
                'warning',
            );
        }

        foreach (array_slice($outcome->rejected, 0, 25) as $rejection) {
            $batch->log($rejection, 'warning');
        }

        if ($outcome->unmatched > 0) {
            $batch->log(
                "{$outcome->unmatched} listings could not be matched and are awaiting review.",
                'warning',
            );
        }
    }

    /**
     * @return Collection<string, FuelType>
     */
    private function fuelTypes(): Collection
    {
        return FuelType::query()->get()->keyBy('code');
    }

    /**
     * The decoded responses of one endpoint from a stored payload.
     *
     * The scraper stores its capture as `{"batchedDataV2": [...], "getSchema":
     * [...]}` — already decoded and guard-stripped, since the guard is a
     * transport detail and there is no reason to make every consumer strip it
     * again. A raw string is still accepted so a payload captured by hand, or
     * by an older scraper, replays without conversion.
     *
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function payloadsOf(array $payload, string $endpoint): array
    {
        $entries = $payload[$endpoint] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $decoded = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $body = json_decode(LookerSchema::stripGuard($entry), true);

                if (is_array($body)) {
                    $decoded[] = $body;
                }

                continue;
            }

            if (is_array($entry)) {
                $decoded[] = $entry;
            }
        }

        return $decoded;
    }
}
