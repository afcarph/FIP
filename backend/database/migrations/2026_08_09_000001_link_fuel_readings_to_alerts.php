<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an alert point at the fuel reading that raised it.
 *
 * `fraud_alerts` is reused rather than joined by a second table. It already
 * carries nullable company, fleet, vehicle, driver and purchase references, an
 * evidence blob, the severity bands and the whole open → investigating →
 * confirmed/dismissed workflow, with an API and a dashboard card already
 * reading from it — a card whose heading has said "Fuel anomalies" since long
 * before there was anything to put in it.
 *
 * A parallel `fuel_alerts` table would have meant two inboxes and two
 * resolution flows for one operator answering one question: is fuel going
 * missing from this vehicle?
 *
 * The column is nullable because most alerts still come from a purchase and
 * have no reading behind them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fraud_alerts', function (Blueprint $table): void {
            $table->foreignId('vehicle_fuel_reading_id')
                ->nullable()
                ->after('fuel_purchase_id')
                ->constrained('vehicle_fuel_readings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fraud_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_fuel_reading_id');
        });
    }
};
