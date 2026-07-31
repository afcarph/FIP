<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\OcrScan;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Services\External\AiServiceClient;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OCR pipeline for photographed station price boards.
 *
 * Flow: upload → store privately → AI service extracts (fuel, price,
 * confidence) triples → validate each line against the local price band →
 * auto-submit or queue for review → on approval, publish through PriceService.
 *
 * Validation matters more than extraction accuracy here: a misread "58.90" as
 * "5.890" must never reach the live price table.
 */
final readonly class OcrScanService
{
    public function __construct(
        private AiServiceClient $ai,
        private PriceService $priceService,
        private PriceRepository $priceRepository,
    ) {}

    public function scan(User $user, UploadedFile $image, ?GasStation $station = null): OcrScan
    {
        $this->assertAcceptable($image);

        $path = $image->store(
            'ocr/'.now()->format('Y/m'),
            ['disk' => config('filesystems.default'), 'visibility' => 'private'],
        );

        $scan = OcrScan::create([
            'user_id' => $user->getKey(),
            'station_id' => $station?->getKey(),
            'image_path' => $path,
            'engine' => 'tesseract',
            'status' => OcrScan::STATUS_PROCESSING,
        ]);

        try {
            $result = $this->ai->scanPriceBoard(
                Storage::get($path) ?? '',
                basename($path),
            );
        } catch (\Throwable $e) {
            $scan->update([
                'status' => OcrScan::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 255),
            ]);

            throw $e;
        }

        $lines = $this->validateLines($result['lines'] ?? [], $station);
        $confidence = $this->overallConfidence($lines);

        $scan->update([
            'raw_text' => $result['raw_text'] ?? null,
            'parsed_payload' => $lines,
            'engine' => $result['engine'] ?? 'tesseract',
            'overall_confidence' => $confidence,
            'status' => $this->deriveStatus($lines, $confidence),
        ]);

        // A high-confidence scan at a known station publishes without a human.
        if ($scan->status === OcrScan::STATUS_PARSED && $station !== null
            && $confidence >= (float) config('fip.ocr.auto_submit_confidence')) {
            $this->publish($scan, $user);
        }

        return $scan->refresh();
    }

    /** Administrator accepts a scan; every valid line becomes a live price. */
    public function approve(OcrScan $scan, User $reviewer, ?array $overrides = null): OcrScan
    {
        if ($scan->station_id === null) {
            throw new DomainException('Assign a station before approving this scan.', 'station_required', 422);
        }

        if ($overrides !== null) {
            $scan->update(['parsed_payload' => $this->validateLines($overrides, $scan->station)]);
        }

        $this->publish($scan, $reviewer);

        $scan->update([
            'status' => OcrScan::STATUS_APPROVED,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
        ]);

        return $scan->refresh();
    }

    public function reject(OcrScan $scan, User $reviewer, string $reason): OcrScan
    {
        $scan->update([
            'status' => OcrScan::STATUS_REJECTED,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'error_message' => mb_substr($reason, 0, 255),
        ]);

        return $scan;
    }

    // ------------------------------------------------------------ internals

    private function publish(OcrScan $scan, User $actor): void
    {
        $station = $scan->station;

        if ($station === null) {
            return;
        }

        foreach ($scan->parsed_payload ?? [] as $line) {
            if (($line['valid'] ?? false) !== true || ! isset($line['fuel_type_id'], $line['price'])) {
                continue;
            }

            $this->priceService->recordPrice(
                station: $station,
                fuelTypeId: (int) $line['fuel_type_id'],
                price: (float) $line['price'],
                source: 'ocr',
                confidence: (float) ($line['confidence'] ?? 0.8),
                reportedBy: $actor->getKey(),
            );
        }
    }

    /**
     * Resolve each extracted label to a fuel type and sanity-check the price
     * against the local median. Lines are annotated, never silently dropped —
     * a reviewer needs to see what the engine got wrong.
     */
    private function validateLines(array $lines, ?GasStation $station): array
    {
        $fuelTypes = FuelType::active()->get();
        $tolerance = (float) config('fip.pricing.max_price_deviation_pct');
        $validated = [];

        foreach ($lines as $line) {
            $price = isset($line['price']) ? round((float) $line['price'], 4) : null;
            $fuelType = $this->matchFuelType($line['fuel_type'] ?? $line['label'] ?? '', $fuelTypes);
            $reason = null;

            if ($fuelType === null) {
                $reason = 'unrecognised_fuel_label';
            } elseif ($price === null || $price <= 0 || $price >= 1000) {
                $reason = 'implausible_price';
            } elseif ($station !== null) {
                $median = $this->priceRepository->cityMedian($station->city_id, $fuelType->getKey());

                if ($median !== null && abs($price - $median) / $median > $tolerance) {
                    $reason = 'price_out_of_local_band';
                }
            }

            $validated[] = [
                'label' => $line['fuel_type'] ?? $line['label'] ?? null,
                'fuel_type_id' => $fuelType?->getKey(),
                'fuel_type_code' => $fuelType?->code,
                'price' => $price,
                'confidence' => round((float) ($line['confidence'] ?? 0), 3),
                'bbox' => $line['bbox'] ?? null,
                'valid' => $reason === null,
                'rejection_reason' => $reason,
            ];
        }

        return $validated;
    }

    /**
     * Fuzzy-match the OCR label against known fuel types. Boards use brand
     * names ("XTRA UNLEADED", "V-Power", "Silver") rather than the codes we
     * store, so the match is on tokens plus a small alias table.
     */
    private function matchFuelType(string $label, $fuelTypes): ?FuelType
    {
        $normalised = mb_strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $label) ?? '');

        $aliases = [
            'gasoline_ron91' => ['ron 91', 'ron91', 'unleaded', 'xtra unleaded', 'regular', 'silver'],
            'gasoline_ron95' => ['ron 95', 'ron95', 'xcs', 'blaze 100', 'v power', 'vpower', 'premium 95', 'gold'],
            'gasoline_ron97' => ['ron 97', 'ron97', 'xtra advance', 'blaze', 'platinum'],
            'diesel' => ['diesel', 'gasoil', 'turbo diesel'],
            'diesel_premium' => ['premium diesel', 'diesel max', 'v power diesel', 'xtra diesel'],
            'kerosene' => ['kerosene', 'kero'],
            'lpg_auto' => ['auto lpg', 'lpg'],
        ];

        foreach ($aliases as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    $match = $fuelTypes->firstWhere('code', $code);

                    if ($match !== null) {
                        return $match;
                    }
                }
            }
        }

        // Fall back to a direct code or name hit.
        return $fuelTypes->first(static fn (FuelType $ft) => str_contains($normalised, mb_strtolower($ft->name))
            || str_contains($normalised, str_replace('_', ' ', $ft->code)));
    }

    private function overallConfidence(array $lines): float
    {
        $valid = array_filter($lines, static fn (array $l) => $l['valid'] ?? false);

        if ($valid === []) {
            return 0.0;
        }

        return round(array_sum(array_column($valid, 'confidence')) / count($valid), 3);
    }

    private function deriveStatus(array $lines, float $confidence): string
    {
        $hasValid = array_filter($lines, static fn (array $l) => $l['valid'] ?? false) !== [];

        return match (true) {
            ! $hasValid => OcrScan::STATUS_NEEDS_REVIEW,
            $confidence >= (float) config('fip.ocr.min_confidence') => OcrScan::STATUS_PARSED,
            default => OcrScan::STATUS_NEEDS_REVIEW,
        };
    }

    private function assertAcceptable(UploadedFile $image): void
    {
        $maxBytes = (int) config('fip.ocr.max_image_mb') * 1024 * 1024;

        if ($image->getSize() > $maxBytes) {
            throw new DomainException(
                sprintf('Image must be %d MB or smaller.', (int) config('fip.ocr.max_image_mb')),
                'image_too_large',
                422,
            );
        }

        if (! in_array($image->getMimeType(), config('fip.ocr.allowed_mimes'), true)) {
            throw new DomainException('Upload a JPEG, PNG, WebP or HEIC image.', 'unsupported_image_type', 422);
        }
    }
}
