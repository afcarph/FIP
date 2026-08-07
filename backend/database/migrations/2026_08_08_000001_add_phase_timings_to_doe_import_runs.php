<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-phase timings and counters for the ingest run log.
 *
 * `duration_seconds` says a run took 62 seconds. It does not say whether that
 * was the DOE's API, the extractor, or our own database — and those fail
 * differently and are somebody else's problem in two cases out of three. A
 * run that doubles in length is only actionable once you know which phase
 * doubled.
 *
 * Every column is nullable. The ingest writes them and the ingest deploys
 * separately from this migration, so rows written by an older ingest must
 * remain valid rather than being backfilled with a zero that reads as "took no
 * time" instead of "was never measured".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doe_import_runs', function (Blueprint $table): void {
            // Candidates that parsed into a report. Not the same as
            // pdfs_discovered: discovery matches on title, and the DOE
            // publishes LPG sheets and circulars whose titles match.
            $table->unsignedInteger('reports_discovered')->default(0)->after('pdfs_downloaded');
            // Documents refused — unreadable layout, failed validation, failed
            // download. Per-document and expected; distinct from run errors.
            $table->unsignedInteger('reports_rejected')->default(0)->after('reports_skipped');

            $table->unsignedInteger('discovery_duration_ms')->nullable()->after('duration_seconds');
            $table->unsignedInteger('download_duration_ms')->nullable()->after('discovery_duration_ms');
            $table->unsignedInteger('extraction_duration_ms')->nullable()->after('download_duration_ms');
            $table->unsignedInteger('validation_duration_ms')->nullable()->after('extraction_duration_ms');
            $table->unsignedInteger('import_duration_ms')->nullable()->after('validation_duration_ms');
            // Measured around the whole run rather than summed from the
            // phases, so time spent outside them stays visible.
            $table->unsignedInteger('total_duration_ms')->nullable()->after('import_duration_ms');

            // Which extractor read each report this run, as JSON. A layout
            // changing silently shows up here first, as a report arriving from
            // a different extractor than the week before.
            $table->text('parser_versions')->nullable()->after('run_id');
            // Pages of the CMS document library walked. A daily run should
            // stay at 1; a creeping number means the lookback window no longer
            // covers the publication gap.
            $table->unsignedInteger('graphql_pages')->nullable()->after('parser_versions');
        });
    }

    public function down(): void
    {
        Schema::table('doe_import_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'reports_discovered',
                'reports_rejected',
                'discovery_duration_ms',
                'download_duration_ms',
                'extraction_duration_ms',
                'validation_duration_ms',
                'import_duration_ms',
                'total_duration_ms',
                'parser_versions',
                'graphql_pages',
            ]);
        });
    }
};
