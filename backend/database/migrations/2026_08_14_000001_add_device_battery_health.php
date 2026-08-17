<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Battery health for a tracking device.
 *
 * `user_devices` is extended rather than joined to, for the same reason the
 * location cache lives there: the fleet health view asks "which devices have
 * gone quiet, and why" across every device at once, and a separate table would
 * put a join on a question that is answered from one small hot row.
 *
 * Three columns, not one. A percentage without a state cannot tell a dead
 * battery from one on charge, and neither can be trusted without knowing when
 * it was read — a device that has been off for a day still reports the 4% it
 * had when it stopped, and showing that as current would send someone looking
 * for a van whose phone simply died. `battery_updated_at` is what makes the
 * other two honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            // 0-100. Unsigned tinyint because a percentage has no use for the
            // range or the precision of anything wider.
            $table->unsignedTinyInteger('battery_percentage')->nullable()->after('last_location_at');

            // charging / discharging / full / unknown. A string rather than a
            // database enum, matching how `platform` is stored here: adding a
            // state to an enum column is a table rebuild, and the platforms
            // disagree about what states exist.
            $table->string('battery_state', 16)->nullable()->after('battery_percentage');

            // When the device read it, not when the row was written. Nullable
            // for every device registered before this shipped, and for every
            // device that has never reported — "unknown" is a real answer here
            // and must not be confused with "full" or with "flat".
            $table->timestamp('battery_updated_at')->nullable()->after('battery_state');

            // The health view's own question: the low-battery filter sorts the
            // fleet by charge, and the freshness column decides whether a
            // reading is worth showing at all.
            $table->index(['battery_percentage', 'battery_updated_at'], 'ix_user_devices_battery');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropIndex('ix_user_devices_battery');
            $table->dropColumn(['battery_percentage', 'battery_state', 'battery_updated_at']);
        });
    }
};
