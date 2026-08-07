<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational tables for the DOE scraper.
 *
 * These hold no prices. Prices live in `station_prices` and
 * `fuel_price_history`, written only by PriceService — the scraper is an
 * ingestion component, not a second pricing model.
 *
 * What is here is the machinery around that: the raw payload as intercepted,
 * so a failed or mis-parsed import can be replayed without re-scraping; a log
 * per batch; and a queue of stations the matcher could not resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doe_import_batches', function (Blueprint $table): void {
            $table->id();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // pending  — captured, not yet imported
            // importing/imported/failed/skipped — set by the import command
            $table->string('status', 16)->default('pending');

            // SHA-256 of the raw payload. The scraper runs daily against a
            // dashboard that changes weekly, so most runs capture a payload
            // identical to yesterday's; the hash is what lets the import skip
            // them without parsing. Not unique — a re-scrape after a parser fix
            // is a legitimate second batch with the same bytes.
            $table->string('payload_hash', 64);

            // The intercepted responses, verbatim. longText because a national
            // batchedDataV2 payload runs to several megabytes, and MEDIUMTEXT's
            // 16MB ceiling is close enough to be a future outage.
            $table->longText('raw_payload')->nullable();

            $table->text('error')->nullable();

            // Set by the import, not the scraper: how many records the payload
            // yielded and what became of them.
            $table->unsignedInteger('records_parsed')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('stations_unmatched')->default(0);

            $table->string('source_url', 500)->nullable();
            $table->string('run_id', 32)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('payload_hash');
            $table->index('started_at');
        });

        Schema::create('doe_import_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('doe_import_batches')
                ->cascadeOnDelete();

            $table->text('message');
            $table->string('level', 16)->default('info');

            // Structured detail — the station that failed to match, the field
            // that would not map. Keeps the message readable while leaving the
            // specifics queryable.
            $table->json('context')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['batch_id', 'level']);
        });

        Schema::create('doe_station_review', function (Blueprint $table): void {
            $table->id();

            // What the DOE published, verbatim. This is the whole record for an
            // unmatched station: there is no DOE station table to point at.
            $table->string('doe_company', 160)->nullable();
            $table->string('doe_station', 200)->nullable();
            $table->string('doe_address', 400)->nullable();
            $table->string('doe_city', 160)->nullable();
            $table->string('doe_province', 160)->nullable();
            $table->string('doe_barangay', 160)->nullable();
            $table->decimal('doe_latitude', 10, 8)->nullable();
            $table->decimal('doe_longitude', 11, 8)->nullable();

            // Identity of the DOE listing, so the same unmatched station across
            // many runs is one review item rather than one per day.
            $table->string('fingerprint', 64)->unique();

            // The matcher's best effort, kept even when it fell below the
            // threshold: a reviewer confirming a 0.72 suggestion is doing far
            // less work than one searching the directory from scratch.
            $table->foreignId('suggested_station_id')
                ->nullable()
                ->constrained('gas_stations')
                ->nullOnDelete();
            $table->decimal('suggested_confidence', 4, 3)->nullable();
            $table->string('suggested_strategy', 32)->nullable();

            // Once resolved, this is what the matcher consults first — a manual
            // mapping is the highest-priority strategy there is.
            $table->foreignId('resolved_station_id')
                ->nullable()
                ->constrained('gas_stations')
                ->nullOnDelete();
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // pending | mapped | ignored
            // `ignored` matters: some DOE listings are depots or closed sites
            // with no platform station, and without it they resurface every day.
            $table->string('status', 16)->default('pending');
            $table->text('notes')->nullable();

            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['doe_company', 'doe_city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doe_station_review');
        Schema::dropIfExists('doe_import_logs');
        Schema::dropIfExists('doe_import_batches');
    }
};
