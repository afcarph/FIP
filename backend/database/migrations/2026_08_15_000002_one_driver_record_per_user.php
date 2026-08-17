<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user is one driver, or none.
 *
 * `drivers.user_id` was nullable with no uniqueness, so two driver records
 * could point at the same account. `User::driverProfile()` is a hasOne and
 * would silently return whichever the database handed back first.
 *
 * That was harmless while nothing read it. Trip scoping reads it: a driver's
 * list is narrowed to their own driver record, so an ambiguous link means a
 * driver could be shown another person's work — the precise thing the
 * narrowing exists to prevent. Found by linking a second record during
 * verification and watching the driver's own trip disappear.
 *
 * Nulls stay unconstrained, which is the common case: a driver record can
 * exist long before the person has a login, and many may have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
        });
    }
};
