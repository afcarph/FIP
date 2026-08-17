<?php

declare(strict_types=1);

namespace App\Domain\Expense\Services;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Station\Models\GasStation;
use App\Domain\Vehicle\Models\Vehicle;
use App\Services\External\AiServiceClient;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Turns a photographed fuel receipt into a draft fill-up for a human to confirm.
 *
 * Three boundaries are deliberate, and worth stating because each one is a
 * thing this service could plausibly have done and should not:
 *
 *  1. It does not create a FuelPurchase. FuelExpenseService owns that, and it
 *     is the only place that enforces the odometer rule and screens for fraud.
 *     A scan that wrote directly would bypass both.
 *
 *  2. It has nothing to do with FuelLevelService. A receipt records a purchase,
 *     not a tank level. The two share a subject and nothing else, and coupling
 *     them would mean an OCR misread could move the fuel gauge.
 *
 *  3. It does not resolve a station to an id automatically. The AI service
 *     returns a brand *hint* read off the header; matching it to one of several
 *     branches of the same brand is a guess, and a wrong station silently
 *     corrupts the price-mismatch fraud rule.
 *
 * The output is a draft and is labelled as one. Everything a client submits
 * afterwards goes through the normal expense endpoint and its validation.
 */
final readonly class ReceiptScanService
{
    public function __construct(private AiServiceClient $ai) {}

    /**
     * @return array{
     *     receipt_path: string,
     *     draft: array<string, mixed>,
     *     confidence: float,
     *     warnings: list<string>,
     *     needs_review: bool
     * }
     */
    public function scan(UploadedFile $image, ?Vehicle $vehicle = null): array
    {
        $this->assertAcceptable($image);

        // Read before storing, not after. `store()` moves the upload, so the
        // bytes have to be taken while the temp file is still there — and
        // reading them back out of object storage afterwards would make the
        // scan depend on a round trip it has no reason to need.
        $contents = (string) file_get_contents($image->getRealPath());
        $filename = $image->getClientOriginalName() ?: 'receipt';

        // Stored so a scan that fails still leaves the image the user
        // submitted — they should not have to photograph it twice because the
        // AI service was down.
        $path = $image->store(
            'receipts/'.now()->format('Y/m'),
            ['disk' => config('filesystems.default'), 'visibility' => 'private'],
        );

        // store() returns false on a write failure. Without this the next line
        // called basename(false) and raised a TypeError, which surfaced to the
        // caller as an opaque 500 describing nothing that had gone wrong.
        if ($path === false) {
            throw new DomainException(
                'The receipt image could not be saved. Try again in a moment.',
                'receipt_storage_failed',
                503,
            );
        }

        $result = $this->ai->scanReceipt($contents, $filename);

        $draft = [
            'litres' => $this->numeric($result, 'litres'),
            'price_per_litre' => $this->numeric($result, 'price_per_litre'),
            'total_cost' => $this->numeric($result, 'total_cost'),
            'odometer' => $this->numeric($result, 'odometer'),
            'purchased_at' => $this->timestamp($result),
            'fuel_type_id' => $this->fuelTypeId($result, $vehicle),
            'station_hint' => $this->text($result, 'station_hint'),
            'station_id' => null,
        ];

        $confidence = round((float) ($result['overall_confidence'] ?? 0), 3);
        $warnings = array_values(array_filter((array) ($result['warnings'] ?? [])));

        // An odometer that goes backwards is the receipt being matched to the
        // wrong vehicle far more often than it is a genuine reading, so it is
        // dropped from the draft rather than pre-filled into a field the API
        // will reject anyway.
        if ($vehicle !== null && $draft['odometer'] !== null && $draft['odometer'] < $vehicle->current_odometer) {
            $warnings[] = sprintf(
                'The odometer read %s km, below this vehicle\'s recorded %s km — check you picked the right vehicle.',
                number_format((float) $draft['odometer'], 0),
                number_format($vehicle->current_odometer, 0),
            );

            $draft['odometer'] = null;
        }

        return [
            'receipt_path' => $path,
            'draft' => $draft,
            'confidence' => $confidence,
            'warnings' => $warnings,
            // Below the OCR threshold the figures are a starting point, not an
            // answer. The flag exists so a client can say so rather than
            // presenting a guess as a reading.
            'needs_review' => $confidence < (float) config('fip.ocr.min_confidence')
                || $draft['litres'] === null
                || $draft['total_cost'] === null,
        ];
    }

    // ---------------------------------------------------------- internals ---

    private function assertAcceptable(UploadedFile $image): void
    {
        $maxBytes = ((int) config('fip.ocr.max_image_mb')) * 1024 * 1024;

        if ($image->getSize() > $maxBytes) {
            throw new DomainException(
                sprintf('Receipt images must be under %d MB.', (int) config('fip.ocr.max_image_mb')),
                'image_too_large',
            );
        }

        if (! in_array($image->getMimeType(), (array) config('fip.ocr.allowed_mimes'), true)) {
            throw new DomainException(
                'That file type cannot be scanned. Use a JPEG, PNG, WebP or HEIC photo.',
                'unsupported_image',
            );
        }
    }

    /** The AI service returns {value, confidence, source_text} per field. */
    private function numeric(array $result, string $field): ?float
    {
        $value = $result[$field]['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function text(array $result, string $field): ?string
    {
        $value = $result[$field]['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(array $result): ?string
    {
        $value = $this->text($result, 'purchased_at');

        if ($value === null) {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // A receipt dated in the future is a misread year, not a time machine.
        return $parsed->isFuture() ? null : $parsed->toDateTimeString();
    }

    /**
     * Map the extracted grade onto a fuel type, falling back to the vehicle's
     * own. The fallback is the safer default: a vehicle's fuel type is a fact,
     * whereas a grade read off faded thermal paper is a guess.
     */
    private function fuelTypeId(array $result, ?Vehicle $vehicle): ?int
    {
        $code = $this->text($result, 'fuel_type');

        if ($code !== null) {
            $id = FuelType::where('code', $code)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return $vehicle?->fuel_type_id;
    }

    /**
     * Candidate stations for a brand hint, for the client to offer as a choice.
     * Never auto-selected — see the class docblock.
     *
     * @return array<int, array{id: int, name: string, brand: string|null}>
     */
    public function stationCandidates(?string $hint, int $limit = 5): array
    {
        if ($hint === null || $hint === '') {
            return [];
        }

        return GasStation::query()
            ->active()
            ->whereHas('brand', fn ($q) => $q->where('name', 'like', '%'.$hint.'%'))
            ->with('brand:id,name')
            ->limit($limit)
            ->get()
            ->map(static fn (GasStation $station) => [
                'id' => $station->getKey(),
                'name' => $station->name,
                'brand' => $station->brand?->name,
            ])
            ->all();
    }
}
