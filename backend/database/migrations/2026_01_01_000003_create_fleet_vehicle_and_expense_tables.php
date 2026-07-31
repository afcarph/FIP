<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fleets, drivers, vehicles and the expense/trip ledger. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('logo_path', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('make_id')->constrained('vehicle_makes')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('body_type', 32)->nullable();
            $table->foreignId('default_fuel_type_id')->nullable()->constrained('fuel_types')->nullOnDelete();
            $table->decimal('tank_capacity', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['make_id', 'name']);
        });

        Schema::create('fleets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('base_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->decimal('monthly_fuel_budget', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_no', 40)->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('phone', 32)->nullable();
            $table->string('licence_number', 40)->nullable();
            $table->string('licence_type', 16)->nullable();
            $table->date('licence_expiry')->nullable();
            $table->date('hired_at')->nullable();
            $table->decimal('safety_score', 5, 2)->default(100);
            $table->decimal('efficiency_score', 5, 2)->default(100);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_no']);
            $table->index('licence_expiry');
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('make_id')->nullable()->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->foreignId('fuel_type_id')->constrained();
            $table->string('nickname', 80)->nullable();
            $table->string('plate_number', 16);
            $table->string('vin', 32)->nullable();
            $table->string('engine_number', 32)->nullable();
            $table->string('vehicle_type', 24)->default('car');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 32)->nullable();
            $table->string('transmission', 16)->nullable();
            $table->unsignedInteger('engine_displacement_cc')->nullable();
            $table->decimal('tank_capacity', 6, 2)->nullable();
            $table->decimal('current_odometer', 12, 2)->default(0);
            $table->decimal('baseline_km_per_litre', 6, 2)->nullable();
            $table->decimal('avg_km_per_litre', 6, 2)->nullable();
            $table->date('registration_expiry')->nullable();
            $table->string('insurance_provider', 120)->nullable();
            $table->string('insurance_policy_no', 64)->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();

            // A plate is unique among live rows; soft-deleted rows may repeat it.
            $table->unique(['plate_number', 'deleted_at']);
            $table->index(['company_id', 'status']);
            $table->index(['fleet_id', 'status']);
            $table->index('registration_expiry');
            $table->index('insurance_expiry');
        });

        Schema::create('vehicle_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'released_at']);
            $table->index(['driver_id', 'released_at']);
        });

        Schema::create('odometer_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->decimal('reading', 12, 2);
            $table->string('source', 16)->default('manual');
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['vehicle_id', 'recorded_at']);
        });

        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('number', 64)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'type']);
            $table->index('expires_on');
        });

        Schema::create('fuel_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('gas_stations')->nullOnDelete();
            $table->foreignId('fuel_type_id')->constrained();
            $table->decimal('litres', 9, 3);
            $table->decimal('price_per_litre', 10, 4);
            $table->decimal('total_cost', 12, 2);
            $table->decimal('odometer', 12, 2)->nullable();
            $table->decimal('distance_since_last', 10, 2)->nullable();
            $table->decimal('km_per_litre', 6, 2)->nullable();
            $table->decimal('cost_per_km', 8, 4)->nullable();
            $table->boolean('is_full_tank')->default(true);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('purchased_at');
            $table->decimal('anomaly_score', 4, 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'purchased_at']);
            $table->index(['user_id', 'purchased_at']);
            $table->index('anomaly_score');
        });

        Schema::create('trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_no', 40)->nullable();
            $table->string('origin_label', 180)->nullable();
            $table->decimal('origin_lat', 10, 7)->nullable();
            $table->decimal('origin_lng', 10, 7)->nullable();
            $table->string('destination_label', 180)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('fuel_consumed_l', 9, 3)->nullable();
            $table->decimal('fuel_cost', 12, 2)->nullable();
            $table->decimal('toll_cost', 12, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 16)->default('planned');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'started_at']);
            $table->index(['fleet_id', 'started_at']);
        });

        Schema::create('route_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_label', 180);
            $table->string('destination_label', 180);
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('optimize_for', 16)->default('cost');
            $table->unsignedSmallInteger('selected_option')->nullable();
            $table->json('options_payload');
            $table->decimal('estimated_savings', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_plans');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('fuel_purchases');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('odometer_readings');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('fleets');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_makes');
    }
};
