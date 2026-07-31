<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Adapts a ReportService dataset to an Excel sheet. Keeping the adapter thin
 * means the same dataset drives PDF, CSV and XLSX without divergence.
 */
class ArrayExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly array $dataset) {}

    public function array(): array
    {
        $keys = array_keys($this->dataset['columns']);

        return array_map(
            static fn (array $row) => array_map(static fn (string $key) => $row[$key] ?? null, $keys),
            $this->dataset['rows'],
        );
    }

    public function headings(): array
    {
        return array_values($this->dataset['columns']);
    }

    public function title(): string
    {
        return mb_substr($this->dataset['title'] ?? 'Report', 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0F172A']],
            ],
        ];
    }
}
