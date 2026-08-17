<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuel telemetry: what the tank holds, and how that changed over time.
 *
 * This extends the existing fleet tables rather than introducing a parallel
 * one. `fuel_purchases` stays what it has always been — a financial ledger of
 * what was bought — and gains only a provenance column. The new table records
 * something the platform has never held: the *level* in the tank at a moment,
 * which is a physical state sample rather than a commercial event.
 *
 * They are deliberately separate. A purchase happens a few times a month and is
 * a financial record with a statutory retention period; a reading can arrive
 * every minute and is operational data that ages out. Folding levels into
 * `fuel_purchases` would put a high-volume time series inside a table indexed,
 * scoped and retained as a ledger — and would leave `litres`, `price_per_litre`
 * and `total_cost` meaningless on every passive reading.
 *
 * No CHECK constraint is declared on either `source` column. `database/schema.sql`
 * documents `chk_odo_source` for the sibling column on `odometer_readings`, but
 * no migration in this project has ever emitted a CHECK and the live database
 * contains none — so adding one here would enforce a rule on the new column
 * that the established one does not carry, and would not survive the SQLite
 * test connection. The vocabulary is enforced in FuelLevelService and in the
 * form request instead; see VehicleFuelReading::SOURCES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            // Current state, denormalised from the newest reading so the fleet
            // dashboard can render one row per vehicle without a correlated
            // subquery per row. `fuel_level_at` is what makes the pair safe to
            // read: without it, "40%" from an hour ago and "40%" from last
            // Tuesday are indistinguishable.
            $table->decimal('current_fuel_pct', 5, 2)->nullable()->after('current_odometer');
            // Stored rather than derived from tank_capacity at read time: the
            // capacity on the vehicle record can be corrected later, and that
            // must not retroactively restate what the tank held.
            $table->decimal('current_fuel_litres', 7, 2)->nullable()->after('current_fuel_pct');
            $table->timestamp('fuel_level_at')->nullable()->after('current_fuel_litres');
        });

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            // Provenance, mirroring odometer_readings.source. Without it a
            // simulated fill-up is indistinguishable from a real one, which
            // makes the simulator unsafe to run against a populated database.
            $table->string('source', 16)->default('manual')->after('purchased_at');

            $table->index(['vehicle_id', 'source']);
        });

        Schema::create('vehicle_fuel_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->decimal('fuel_pct', 5, 2);
            $table->decimal('fuel_litres', 7, 2)->nullable();

            // Signed change from the chronologically preceding reading. Stored
            // rather than computed on read so that detecting an abnormal drop
            // is a single indexed scan instead of a self-join over the series.
            $table->decimal('delta_pct', 6, 2)->nullable();

            $table->string('source', 16)->default('manual');

            // Set when a rise is explained by a recorded fill-up. An
            // unexplained increase matters as much as an unexplained drop.
            $table->foreignId('fuel_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // The reading's own clock. Distinct from created_at, which is the
            // server's — the gap between them is how late a batched or
            // offline-buffered reading arrived.
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            // Idempotency guard. A retried push of the same batch must not
            // double the series and skew every delta computed across it.
            $table->unique(['vehicle_id', 'recorded_at', 'source'], 'uq_vfr_vehicle_time_source');

            // The detection-window scan: one vehicle's series, newest first.
            $table->index(['vehicle_id', 'recorded_at'], 'ix_vfr_vehicle_time');
            // Serves `--purge`, which deletes by source alone.
            $table->index('source', 'ix_vfr_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_fuel_readings');

        Schema::table('fuel_purchases', function (Blueprint $table): void {
            $table->dropIndex(['vehicle_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['current_fuel_pct', 'current_fuel_litres', 'fuel_level_at']);
        });
    }
};
