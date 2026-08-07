<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Builds `getSchema` and `batchedDataV2` payloads in the shape Looker emits.
 *
 * The field ids here are fixture data, not configuration. Nothing in the
 * application knows them — that is the point of the schema mapper, and these
 * tests would pass with any other ids.
 */
trait BuildsDoePayloads
{
    /**
     * Published labels against generated ids, as getSchema returns them.
     *
     * @return array<string, string>
     */
    protected function doeSchemaFields(): array
    {
        return [
            'qt_fgaojmiemc' => 'Timestamp',
            'qt_85e4fhiemc' => 'Gas Station',
            'qt_a1b2c3diemc' => 'Company',
            'qt_d4e5f6giemc' => 'City/Municipality',
            'qt_g7h8i9jiemc' => 'Province',
            'qt_z1x2c3viemc' => 'Address',
            'qt_m4n5o6piemc' => 'RON 91',
            'qt_p7q8r9siemc' => 'RON 95',
            'qt_y7z8a9biemc' => 'Diesel',
        ];
    }

    /**
     * @param array<string, string>|null $fields
     * @return array<string, mixed>
     */
    protected function doeSchemaPayload(?array $fields = null): array
    {
        $fields ??= $this->doeSchemaFields();

        return [
            'datasourceSchema' => [
                'dataset' => [
                    'fields' => array_map(
                        static fn (string $id, string $label): array => ['name' => $id, 'label' => $label],
                        array_keys($fields),
                        array_values($fields),
                    ),
                ],
            ],
        ];
    }

    /**
     * A Looker column: values with the nulls *omitted*, and their positions
     * recorded separately. This is the shape that breaks a naive parser.
     *
     * @param list<mixed> $values
     * @return array<string, mixed>
     */
    protected function doeColumn(string $type, array $values): array
    {
        return [
            $type => [
                'values' => array_values(array_filter($values, static fn ($v): bool => $v !== null)),
                'nullIndex' => array_values(array_keys(array_filter(
                    $values,
                    static fn ($v): bool => $v === null,
                ))),
            ],
        ];
    }

    /**
     * A batchedDataV2 payload.
     *
     * Each row is [station, company, city, province, address, ron91, ron95, diesel].
     *
     * @param list<array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?float, 6: ?float, 7: ?float}> $rows
     * @return array<string, mixed>
     */
    protected function doeDataPayload(string $priceDate, array $rows): array
    {
        $fieldIds = [
            'qt_fgaojmiemc',   // Timestamp
            'qt_85e4fhiemc',   // Gas Station
            'qt_a1b2c3diemc',  // Company
            'qt_d4e5f6giemc',  // City
            'qt_g7h8i9jiemc',  // Province
            'qt_z1x2c3viemc',  // Address
            'qt_m4n5o6piemc',  // RON 91
            'qt_p7q8r9siemc',  // RON 95
            'qt_y7z8a9biemc',  // Diesel
        ];

        $column = fn (int $index, string $type): array => $this->doeColumn(
            $type,
            array_map(static fn (array $row) => $row[$index] ?? null, $rows),
        );

        return [
            'dataResponse' => [
                [
                    'dataSubset' => [
                        [
                            'dataSubsetRequest' => ['requestedFields' => $fieldIds],
                            'dataset' => [
                                'tableDataset' => [
                                    'column' => [
                                        $this->doeColumn('stringColumn', array_fill(0, count($rows), $priceDate)),
                                        $column(0, 'stringColumn'),
                                        $column(1, 'stringColumn'),
                                        $column(2, 'stringColumn'),
                                        $column(3, 'stringColumn'),
                                        $column(4, 'stringColumn'),
                                        $column(5, 'doubleColumn'),
                                        $column(6, 'doubleColumn'),
                                        $column(7, 'doubleColumn'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The envelope the scraper stores in `doe_import_batches.raw_payload`.
     *
     * @param list<array<string, mixed>> $dataPayloads
     * @param list<array<string, mixed>>|null $schemaPayloads
     */
    protected function doeEnvelope(array $dataPayloads, ?array $schemaPayloads = null): string
    {
        return (string) json_encode([
            'getSchema' => $schemaPayloads ?? [$this->doeSchemaPayload()],
            'batchedDataV2' => $dataPayloads,
        ]);
    }
}
