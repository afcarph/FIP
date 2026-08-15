<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the trips table into something a dispatcher can work.
 *
 * The table already existed as a cost record — where a vehicle went, what the
 * fuel and tolls came to — with a status column nothing ever wrote and no way
 * to create a row through the API. What it lacked was the operational half:
 * who ordered the trip, when it was dispatched, why it was cancelled.
 *
 * `company_id` is the one that matters. Trips were reachable only through the
 * vehicle, so every tenancy question meant a join, and `forUser()` had nothing
 * to filter on. It is denormalised from the vehicle deliberately: a tenancy
 * guard rail should not depend on a relationship staying loaded.
 *
 * Additive throughout. `started_at` and `ended_at` keep their meaning, which
 * matters because the fleet dashboard already derives "on trip" from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();

            // Who ordered it, kept for the same reason assignments keep their
            // dates: an operational record answers "who decided this".
            $table->foreignId('created_by')->nullable()->after('fleet_id')
                ->constrained('users')->nullOnDelete();

            $table->string('purpose', 180)->nullable()->after('destination_lng');
            $table->timestamp('scheduled_for')->nullable()->after('purpose');
            $table->text('notes')->nullable()->after('scheduled_for');

            // Odometer is recorded on the trip rather than pushed onto the
            // vehicle: fuel purchases already maintain the vehicle's reading,
            // and two writers on one column disagree eventually.
            $table->unsignedInteger('odometer_start')->nullable()->after('notes');
            $table->unsignedInteger('odometer_end')->nullable()->after('odometer_start');

            // started_at and ended_at already exist and keep their meaning.
            $table->timestamp('dispatched_at')->nullable()->after('started_at');
            $table->timestamp('cancelled_at')->nullable()->after('ended_at');
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_at');

            $table->index(['company_id', 'status']);
        });

        // The old default named a state the workflow does not have. Nothing has
        // ever written a trip row in any environment, so this renames an unused
        // default rather than migrating data.
        Schema::table('trips', function (Blueprint $table): void {
            $table->string('status', 16)->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'purpose', 'scheduled_for', 'notes', 'odometer_start', 'odometer_end',
                'dispatched_at', 'cancelled_at', 'cancellation_reason',
            ]);
            $table->string('status', 16)->default('planned')->change();
        });
    }
};
