<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Doe\Services\PayloadParser;
use App\Domain\Doe\Services\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsDoePayloads;

/**
 * Parsing, and above all the null mask.
 *
 * Every other failure in this parser is loud. Ignoring the null mask is the
 * one that produces a complete, plausible, entirely wrong dataset — real
 * prices attached to the wrong stations, undetectable anywhere downstream.
 */
class DoePayloadParserTest extends TestCase
{
    use BuildsDoePayloads;

    private function parser(): PayloadParser
    {
        return new PayloadParser;
    }

    private function mapper(): SchemaMapper
    {
        return (new SchemaMapper)->build([$this->doeSchemaPayload()]);
    }

    // -- the null mask -------------------------------------------------------

    public function test_it_reinserts_omitted_nulls(): void
    {
        // Looker sent three values and a mask saying position 1 was null.
        $this->assertSame(
            [10, null, 30, 40],
            $this->parser()->expandNulls([10, 30, 40], [1], 4),
        );
    }

    public function test_it_handles_a_leading_null(): void
    {
        $this->assertSame([null, 20, 30], $this->parser()->expandNulls([20, 30], [0], 3));
    }

    public function test_it_handles_a_trailing_null(): void
    {
        $this->assertSame([10, 20, null], $this->parser()->expandNulls([10, 20], [2], 3));
    }

    public function test_it_handles_consecutive_nulls(): void
    {
        $this->assertSame([10, null, null], $this->parser()->expandNulls([10], [1, 2], 3));
    }

    public function test_an_all_null_column_becomes_all_null(): void
    {
        $this->assertSame([null, null, null], $this->parser()->expandNulls([], [0, 1, 2], 3));
    }

    public function test_no_mask_leaves_the_column_alone(): void
    {
        $this->assertSame([1, 2, 3], $this->parser()->expandNulls([1, 2, 3], [], 3));
    }

    public function test_ignoring_the_mask_would_shift_every_later_row(): void
    {
        // Stated directly: without the mask, row 1 takes row 2's price and the
        // error propagates to the end of the column.
        $naive = [58.20, 57.60];
        $correct = $this->parser()->expandNulls($naive, [1], 3);

        $this->assertSame([58.20, null, 57.60], $correct);
        $this->assertNotSame($naive[1], $correct[1]);
    }

    // -- records -------------------------------------------------------------

    public function test_it_parses_a_realistic_payload(): void
    {
        $payload = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Quezon City', 'Metro Manila', '123 EDSA', 58.20, 62.40, 56.85],
            ['Shell Ortigas', 'Shell', 'Pasig', 'Metro Manila', '9 Ortigas Ave', null, 63.10, 57.40],
            ['SEAOIL Pasig', 'SEAOIL', 'Pasig', 'Metro Manila', '5 Shaw Blvd', 57.60, 61.85, 56.20],
        ]);

        $records = $this->parser()->parse([$payload], $this->mapper());

        $this->assertCount(3, $records);

        $first = $records[0];
        $this->assertSame('Petron EDSA', $first->station);
        $this->assertSame('Petron', $first->company);
        $this->assertSame('2026-08-06', $first->priceDate->toDateString());
        $this->assertSame(62.40, $first->prices['gasoline_ron95']);
    }

    public function test_nulls_land_on_the_right_rows(): void
    {
        // Shell reported no RON 91. A parser ignoring the mask would give it
        // 57.60 — a real price, on the wrong station.
        $payload = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Quezon City', 'NCR', 'a', 58.20, 62.40, 56.85],
            ['Shell Ortigas', 'Shell', 'Pasig', 'NCR', 'b', null, 63.10, 57.40],
            ['SEAOIL Pasig', 'SEAOIL', 'Pasig', 'NCR', 'c', 57.60, 61.85, 56.20],
        ]);

        $byStation = [];
        foreach ($this->parser()->parse([$payload], $this->mapper()) as $record) {
            $byStation[$record->station] = $record;
        }

        $this->assertArrayNotHasKey('gasoline_ron91', $byStation['Shell Ortigas']->prices);
        $this->assertSame(57.60, $byStation['SEAOIL Pasig']->prices['gasoline_ron91']);
        $this->assertSame(58.20, $byStation['Petron EDSA']->prices['gasoline_ron91']);
    }

    public function test_a_row_with_no_prices_is_dropped(): void
    {
        // Storing it would put a hole in the series that reads as a station
        // that stopped selling fuel.
        $payload = $this->doeDataPayload('20260806', [
            ['Empty Station', 'Petron', 'Pasig', 'NCR', 'a', null, null, null],
        ]);

        $this->assertSame([], $this->parser()->parse([$payload], $this->mapper()));
    }

    public function test_a_row_without_a_station_is_dropped(): void
    {
        $payload = $this->doeDataPayload('20260806', [
            [null, 'Petron', 'Pasig', 'NCR', 'a', 58.20, 62.40, 56.85],
        ]);

        $this->assertSame([], $this->parser()->parse([$payload], $this->mapper()));
    }

    public function test_a_dataset_without_field_ids_is_an_error_not_a_guess(): void
    {
        // Content can identify the dimensions but never which grade a price
        // column is. A dataset whose RON 91 and RON 95 might be swapped is
        // worse than no dataset, because it looks fine.
        $payload = [
            'dataResponse' => [[
                'dataSubset' => [[
                    'dataset' => ['tableDataset' => ['column' => [
                        $this->doeColumn('doubleColumn', [56.0]),
                    ]]],
                ]],
            ]],
        ];

        $parser = $this->parser();
        $records = $parser->parse([$payload], $this->mapper());

        $this->assertSame([], $records);
        $this->assertNotEmpty(array_filter(
            $parser->errors(),
            static fn (string $e): bool => str_contains($e, 'field ids'),
        ));
    }

    public function test_one_bad_tile_does_not_cost_the_others(): void
    {
        // A dashboard page carries several tiles, and the summary tiles
        // routinely have a shape we cannot use.
        $good = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Quezon City', 'NCR', 'a', 58.20, 62.40, 56.85],
        ]);
        $bad = ['dataResponse' => [[
            'dataSubset' => [[
                'dataset' => ['tableDataset' => ['column' => [$this->doeColumn('doubleColumn', [1.0])]]],
            ]],
        ]]];

        $parser = $this->parser();
        $records = $parser->parse([$good, $bad], $this->mapper());

        $this->assertCount(1, $records);
        $this->assertCount(1, $parser->errors());
    }

    public function test_rows_for_one_station_and_date_are_merged(): void
    {
        // The dashboard serves a station from more than one tile, each with a
        // different subset of the grades. Taking the later row wholesale
        // discards prices only the earlier one carried.
        $first = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Quezon City', 'NCR', 'a', null, 62.40, null],
        ]);
        $second = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Quezon City', 'NCR', 'a', null, null, 56.85],
        ]);

        $records = $this->parser()->parse([$first, $second], $this->mapper());

        $this->assertCount(1, $records);
        $this->assertSame(62.40, $records[0]->prices['gasoline_ron95']);
        $this->assertSame(56.85, $records[0]->prices['diesel']);
    }

    public function test_the_same_brand_in_different_cities_stays_separate(): void
    {
        $payload = $this->doeDataPayload('20260806', [
            ['Petron', 'Petron', 'Pasig', 'NCR', 'a', null, 62.40, null],
            ['Petron', 'Petron', 'Makati', 'NCR', 'b', null, 63.00, null],
        ]);

        $this->assertCount(2, $this->parser()->parse([$payload], $this->mapper()));
    }

    public function test_it_reads_the_date_formats_looker_emits(): void
    {
        foreach (['20260806', '2026-08-06', '2026/08/06'] as $format) {
            $payload = $this->doeDataPayload($format, [
                ['Petron EDSA', 'Petron', 'Pasig', 'NCR', 'a', null, 62.40, null],
            ]);

            $records = $this->parser()->parse([$payload], $this->mapper());

            $this->assertSame('2026-08-06', $records[0]->priceDate->toDateString(), $format);
        }
    }

    public function test_the_parser_does_not_judge_plausibility(): void
    {
        // Whether a price is sane is PriceService's call, and the importer must
        // not pre-empt it — a second opinion here would be a second set of
        // business rules to keep in step.
        $payload = $this->doeDataPayload('20260806', [
            ['Petron EDSA', 'Petron', 'Pasig', 'NCR', 'a', null, 99999.0, null],
        ]);

        $records = $this->parser()->parse([$payload], $this->mapper());

        $this->assertSame(99999.0, $records[0]->prices['gasoline_ron95']);
    }
}
