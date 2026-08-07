<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the DOE price monitoring reports.
 *
 * These hold something the platform has never had. `station_prices` and
 * `fuel_price_history` are per-station pump prices; `price_advisories` are
 * per-region weekly *changes*. What the DOE publishes weekly is neither: it is
 * a *level* per city, per product, per brand, as a min-max range, plus a
 * common price. So nothing here duplicates an existing table.
 *
 * There is deliberately no `fuel_stations` table. The source PDFs contain no
 * station-level data whatsoever — no names, no addresses, no coordinates — so
 * such a table could only ever be empty. `gas_stations` remains the platform's
 * station directory.
 *
 * The schema is created here rather than by the ingest service: the platform
 * owns its own database structure, and the Python side only writes rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_reports', function (Blueprint $table): void {
            $table->id();

            // As printed in the document. Read from the PDF header, never from
            // the filename — the DOE publishes `region-v-bicol-8-pdf`, which
            // carries no date, alongside `ncr-price-monitoring-07282026-pdf`.
            $table->string('region', 120);

            $table->date('publication_date')->nullable();
            $table->date('coverage_start');
            $table->date('coverage_end');
            // The week the prices cover and the days they were collected are
            // different things, and the document states both.
            $table->date('monitoring_date')->nullable();

            $table->string('source_url', 500)->nullable();
            $table->string('pdf_filename', 255);
            // Where the original is kept, so extraction can be re-run against
            // it after a fix. The DOE keeps no accessible archive.
            $table->string('pdf_path', 500)->nullable();
            $table->string('checksum', 64);

            // Which extractor won and what it scored. A report imported at
            // 0.60 is worth a look before one imported at 0.98.
            $table->string('extractor', 40)->nullable();
            $table->decimal('quality', 5, 4)->nullable();

            $table->unsignedInteger('areas_count')->default(0);
            $table->unsignedInteger('rows_count')->default(0);

            $table->timestamps();

            // The duplicate-report rule: byte-identical means already imported.
            $table->unique('checksum', 'uq_fuel_reports_checksum');
            // And the semantic one. A region publishes one report per week, so
            // a re-issued PDF is a correction to that week rather than a second
            // report — which the checksum alone would let in, and which would
            // double every average computed across it.
            $table->unique(['region', 'coverage_start'], 'uq_fuel_reports_region_week');

            $table->index(['coverage_start', 'coverage_end'], 'ix_fuel_reports_coverage');
            $table->index('region', 'ix_fuel_reports_region');
        });

        Schema::create('fuel_prices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('report_id')
                ->constrained('fuel_reports')
                ->cascadeOnDelete();

            // City or municipality, as the DOE prints it.
            $table->string('area', 160);

            // Only on the layouts that publish one: the Visayas reports group
            // cities by province, NCR does not. Worth storing rather than
            // discarding — two municipalities can share a name across
            // provinces, and without it their rows collide on the key below.
            $table->string('province', 160)->nullable();

            // The DOE's own product label, kept verbatim for traceability.
            $table->string('product', 40);
            // The platform's fuel_types.code, resolved during extraction so
            // this lands in FIP's vocabulary rather than a parallel one.
            // Nullable because the DOE publishes RON 100 and the platform has
            // no fuel type for it — carried rather than silently filed under
            // RON 97, which would read as a plausible price for another grade.
            $table->string('fuel_code', 40)->nullable();

            // NULL on the row carrying the area's overall range and common
            // price, which the DOE prints as its own column rather than
            // deriving — so it is stored as published, not recomputed.
            $table->string('brand', 80)->nullable();

            $table->decimal('min_price', 8, 2)->nullable();
            $table->decimal('max_price', 8, 2)->nullable();
            $table->decimal('common_price', 8, 2)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['report_id', 'area', 'product', 'brand'],
                'uq_fuel_prices_cell',
            );

            $table->index('area', 'ix_fuel_prices_area');
            $table->index('province', 'ix_fuel_prices_province');
            $table->index('brand', 'ix_fuel_prices_brand');
            $table->index('fuel_code', 'ix_fuel_prices_fuel');
            // Serves the trend query: one grade's series across reports.
            $table->index(['fuel_code', 'report_id'], 'ix_fuel_prices_fuel_report');
        });

        Schema::create('doe_import_runs', function (Blueprint $table): void {
            $table->id();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();

            $table->unsignedInteger('pdfs_discovered')->default(0);
            $table->unsignedInteger('pdfs_downloaded')->default(0);
            $table->unsignedInteger('reports_imported')->default(0);
            $table->unsignedInteger('reports_skipped')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_updated')->default(0);

            // running | success | partial | failed | no_changes
            //
            // `no_changes` is its own status on purpose: a week where the DOE
            // published nothing is a healthy run, and without a way to say so
            // it is indistinguishable from a scheduler that died.
            $table->string('status', 16)->default('running');
            $table->text('errors')->nullable();
            $table->string('run_id', 32)->nullable();

            $table->index('started_at', 'ix_doe_import_runs_started');
            $table->index('status', 'ix_doe_import_runs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doe_import_runs');
        Schema::dropIfExists('fuel_prices');
        Schema::dropIfExists('fuel_reports');
    }
};
