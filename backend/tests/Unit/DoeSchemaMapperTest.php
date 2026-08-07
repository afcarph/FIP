<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Doe\Services\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsDoePayloads;

/**
 * The mapper's job is to make the importer survive a dashboard edit.
 *
 * Looker reissues its field ids whenever the report changes, so an importer
 * pinned to them does not fail loudly — it keeps running and imports nothing.
 * Every test here uses ids that appear nowhere in the application.
 */
class DoeSchemaMapperTest extends TestCase
{
    use BuildsDoePayloads;

    private function mapper(array $fields): SchemaMapper
    {
        return (new SchemaMapper)->build([$this->doeSchemaPayload($fields)]);
    }

    public function test_it_resolves_generated_ids_to_platform_fuel_codes(): void
    {
        // The fuel side resolves to fuel_types.code, so the mapping lands in
        // the platform's own vocabulary rather than a parallel one.
        $mapper = $this->mapper($this->doeSchemaFields());

        $this->assertSame('gasoline_ron95', $mapper->columnFor('qt_p7q8r9siemc'));
        $this->assertSame('diesel', $mapper->columnFor('qt_y7z8a9biemc'));
        $this->assertSame('station', $mapper->columnFor('qt_85e4fhiemc'));
    }

    public function test_the_same_labels_resolve_under_completely_different_ids(): void
    {
        // The property that matters. If this fails, a dashboard edit silently
        // stops the import.
        $mapper = $this->mapper([
            'qt_totallydifferent' => 'Gas Station',
            'calc_9f8e7d6c5b' => 'RON 95',
            '_n_abcdef123456' => 'Diesel',
        ]);

        $this->assertSame('station', $mapper->columnFor('qt_totallydifferent'));
        $this->assertSame('gasoline_ron95', $mapper->columnFor('calc_9f8e7d6c5b'));
        $this->assertSame('diesel', $mapper->columnFor('_n_abcdef123456'));
        $this->assertTrue($mapper->isUsable());
    }

    public function test_diesel_plus_is_not_swallowed_by_diesel(): void
    {
        // "Diesel Plus" contains "Diesel". Wrong pattern order files a premium
        // product's price under regular diesel, which reads as a plausible
        // price rather than as a bug.
        $mapper = $this->mapper([
            'qt_dieselaaaaa' => 'Diesel',
            'qt_dieselplusb' => 'Diesel Plus',
        ]);

        $this->assertSame('diesel', $mapper->columnFor('qt_dieselaaaaa'));
        $this->assertSame('diesel_premium', $mapper->columnFor('qt_dieselplusb'));
    }

    public function test_the_qualifier_may_come_before_the_grade(): void
    {
        $mapper = $this->mapper(['qt_euro5diesel' => 'Euro 5 Diesel']);

        $this->assertSame('diesel_premium', $mapper->columnFor('qt_euro5diesel'));
    }

    public function test_a_plus_sign_is_not_lost_to_punctuation_stripping(): void
    {
        $mapper = $this->mapper(['qt_dieselplus1' => 'Diesel+']);

        $this->assertSame('diesel_premium', $mapper->columnFor('qt_dieselplus1'));
    }

    public function test_ron_100_is_recognised_and_not_read_as_ron_10(): void
    {
        $mapper = $this->mapper(['qt_ron100aaaa' => 'RON 100']);

        $this->assertSame('gasoline_ron100', $mapper->columnFor('qt_ron100aaaa'));
    }

    public function test_it_tolerates_the_report_authors_formatting(): void
    {
        $mapper = $this->mapper([
            'qt_station0001' => '  gas_station  ',
            'qt_ron95000001' => 'RON_95',
            'qt_city00000001' => 'Municipality',
        ]);

        $this->assertSame('station', $mapper->columnFor('qt_station0001'));
        $this->assertSame('gasoline_ron95', $mapper->columnFor('qt_ron95000001'));
        $this->assertSame('city', $mapper->columnFor('qt_city00000001'));
    }

    public function test_it_finds_fields_wherever_they_are_nested(): void
    {
        // Looker moves this nesting between report versions, so the walk must
        // not depend on a fixed path.
        $flat = (new SchemaMapper)->build([
            ['fields' => [['id' => 'qt_station0001', 'displayName' => 'Gas Station']]],
        ]);

        $this->assertSame('station', $flat->columnFor('qt_station0001'));
    }

    public function test_it_merges_several_schema_responses(): void
    {
        // A dashboard whose map tile has its own data source returns two.
        $mapper = (new SchemaMapper)->build([
            $this->doeSchemaPayload(['qt_station0001' => 'Gas Station']),
            $this->doeSchemaPayload(['qt_diesel00001' => 'Diesel']),
        ]);

        $this->assertSame('station', $mapper->columnFor('qt_station0001'));
        $this->assertSame('diesel', $mapper->columnFor('qt_diesel00001'));
    }

    public function test_a_second_id_claiming_a_taken_column_is_rejected(): void
    {
        // Otherwise array order decides which field the prices come from.
        $mapper = $this->mapper([
            'qt_diesel00001' => 'Diesel',
            'qt_diesel00002' => 'Diesel Price',
        ]);

        $this->assertSame('diesel', $mapper->columnFor('qt_diesel00001'));
        $this->assertNull($mapper->columnFor('qt_diesel00002'));
        $this->assertNotEmpty(array_filter(
            $mapper->unmapped(),
            static fn (string $entry): bool => str_contains($entry, 'duplicate'),
        ));
    }

    public function test_a_field_whose_label_is_its_own_id_is_ignored(): void
    {
        $mapper = (new SchemaMapper)->build([
            ['fields' => [['name' => 'qt_station0001', 'label' => 'qt_station0001']]],
        ]);

        $this->assertSame([], $mapper->labels());
    }

    public function test_a_mapping_without_a_fuel_is_not_usable(): void
    {
        // It would import rows of nothing and report success doing it.
        $this->assertFalse($this->mapper(['qt_station0001' => 'Gas Station'])->isUsable());
    }

    public function test_a_mapping_without_a_station_is_not_usable(): void
    {
        $this->assertFalse($this->mapper(['qt_diesel00001' => 'Diesel'])->isUsable());
    }

    public function test_unrecognised_labels_are_recorded_rather_than_dropped(): void
    {
        $mapper = $this->mapper([
            'qt_station0001' => 'Gas Station',
            'qt_volume00001' => 'Volume Sold',
        ]);

        $this->assertContains('Volume Sold', $mapper->unmapped());
    }
}
