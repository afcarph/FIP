<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device registration and vehicle location history.
 *
 * `user_devices` is extended rather than replaced. The mobile app already
 * persists one installation identifier and sends it as `X-Device-Id` on every
 * request, and AuthService already upserts a row on `(user_id, device_uuid)`.
 * A separate `devices` table would have forked device identity in two, leaving
 * the platform unable to say which row a given phone is.
 *
 * `device_locations` is a new table, modelled on `vehicle_fuel_readings`: a
 * high-volume append-only series with its own clock, kept apart from the small
 * hot row that describes the device. Folding positions into `user_devices`
 * would grow a table that is read on every authenticated request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            // The vehicle this device currently reports for. Nullable because
            // most devices are ordinary phones that never report a position:
            // registration for push and registration for tracking are the same
            // row at different levels of privilege.
            $table->foreignId('vehicle_id')->nullable()->after('user_id')->constrained()->nullOnDelete();

            $table->string('app_version', 24)->nullable()->after('platform');
            $table->string('os_version', 32)->nullable()->after('app_version');

            // Revocation is a timestamp rather than a boolean so the audit
            // trail records *when* a device stopped being trusted. A revoked
            // device keeps its history; it simply cannot write any more.
            $table->timestamp('revoked_at')->nullable()->after('is_trusted');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();

            // Cache of the newest position, so a fleet dashboard can render one
            // row per vehicle without a correlated subquery per row. Mirrors
            // how `vehicles.current_fuel_pct` caches the newest reading.
            $table->decimal('last_latitude', 10, 7)->nullable()->after('last_seen_at');
            $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
            $table->timestamp('last_location_at')->nullable()->after('last_longitude');

            // "Which device is reporting for this vehicle" and "which devices
            // have gone quiet" are the two questions the fleet views ask.
            $table->index(['vehicle_id', 'revoked_at'], 'ix_user_devices_vehicle');
            $table->index('last_location_at', 'ix_user_devices_last_location');
        });

        Schema::create('device_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('user_devices')->cascadeOnDelete();

            // Stamped at write time from the device's association, never taken
            // from the request body. A device reassigned to another vehicle
            // tomorrow must not rewrite where it has been today.
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Everything below is optional because the platform does not always
            // supply it: a coarse network fix has no heading, and a stationary
            // device reports no speed. Storing a zero where the device said
            // nothing would invent a measurement.
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->decimal('speed_kph', 6, 2)->nullable();
            $table->decimal('heading_deg', 5, 2)->nullable();

            // The device's own clock, and the server's. The gap between them is
            // how long a reading sat in an offline queue, which is the only way
            // to tell a stale position from a current one.
            $table->timestamp('recorded_at');
            $table->timestamp('received_at');

            // Idempotency guard for a retried offline flush. Without it a
            // dropped connection turns one journey into two overlapping ones.
            $table->unique(['device_id', 'recorded_at'], 'uq_device_locations_device_time');

            // History for one vehicle over a window — the query the history
            // endpoint runs, and the one the pruner uses.
            $table->index(['vehicle_id', 'recorded_at'], 'ix_device_locations_vehicle_time');
            $table->index(['device_id', 'recorded_at'], 'ix_device_locations_device_time');
            // Retention sweeps by age alone, across every vehicle.
            $table->index('recorded_at', 'ix_device_locations_recorded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_locations');

        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropIndex('ix_user_devices_vehicle');
            $table->dropIndex('ix_user_devices_last_location');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn([
                'app_version', 'os_version', 'revoked_at',
                'last_latitude', 'last_longitude', 'last_location_at',
            ]);
        });
    }
};
