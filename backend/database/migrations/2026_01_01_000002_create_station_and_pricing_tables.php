<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fuel reference data, the station directory and the price intelligence tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 80);
            $table->string('category', 16);
            $table->unsignedSmallInteger('octane')->nullable();
            $table->string('unit', 8)->default('L');
            $table->char('color_hex', 7)->default('#2563EB');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 120);
            $table->string('logo_path', 255)->nullable();
            $table->string('website', 180)->nullable();
            $table->char('color_hex', 7)->default('#0F172A');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('amenities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->string('icon', 48)->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->string('icon', 48)->nullable();
            $table->timestamps();
        });

        Schema::create('gas_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained();
            $table->foreignId('operator_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();
            $table->string('address_line', 255);
            $table->foreignId('city_id')->constrained();
            $table->string('postal_code', 12)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('phone', 32)->nullable();
            $table->boolean('is_24_hours')->default(false);
            $table->boolean('has_ev_charging')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['city_id', 'status']);
            $table->index(['brand_id', 'status']);
            // Bounding-box pruning for radius search.
            $table->index(['latitude', 'longitude']);
        });

        // Generated spatial column + SPATIAL index — MySQL only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE gas_stations
                ADD COLUMN location POINT
                    GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED NOT NULL,
                ADD SPATIAL INDEX spx_gas_stations_location (location)
            ');
        }

        Schema::create('station_amenity', function (Blueprint $table): void {
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['station_id', 'amenity_id']);
        });

        Schema::create('station_payment_method', function (Blueprint $table): void {
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->primary(['station_id', 'payment_method_id']);
        });

        Schema::create('station_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);

            $table->unique(['station_id', 'day_of_week']);
        });

        Schema::create('station_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 255);
            $table->string('caption', 180)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['station_id', 'status']);
        });

        Schema::create('station_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['station_id', 'user_id']);
        });

        Schema::create('station_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->constrained();
            $table->decimal('price', 10, 4);
            $table->decimal('previous_price', 10, 4)->nullable();
            $table->string('source', 16)->default('operator');
            $table->decimal('confidence', 4, 3)->default(1);
            $table->timestamp('effective_at');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'fuel_type_id']);
            $table->index(['fuel_type_id', 'price']);
            $table->index('effective_at');
        });

        // Derived column: how much the price moved on the last change.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE station_prices
                ADD COLUMN change_amount DECIMAL(10,4)
                    GENERATED ALWAYS AS (price - COALESCE(previous_price, price)) STORED
            ');
        }

        Schema::create('fuel_price_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('station_id');
            $table->unsignedBigInteger('fuel_type_id');
            $table->unsignedBigInteger('region_id')->nullable();
            $table->decimal('price', 10, 4);
            $table->string('source', 16)->default('operator');
            $table->date('recorded_on');
            $table->timestamp('recorded_at');

            $table->index(['station_id', 'fuel_type_id', 'recorded_on']);
            $table->index(['region_id', 'fuel_type_id', 'recorded_on']);
            $table->index('recorded_on');
        });

        Schema::create('price_advisories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fuel_type_id')->constrained();
            $table->foreignId('region_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->timestamp('effective_at');
            $table->decimal('change_amount', 10, 4);
            // 16, not 8: the longest permitted value is 'no_change' (9).
            $table->string('direction', 16);
            $table->string('source', 24)->default('doe');
            $table->string('source_url', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['fuel_type_id', 'region_id', 'week_start'], 'uq_price_advisories');
            $table->index('week_start');
        });

        Schema::create('price_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_type', 24)->default('price');
            $table->decimal('price', 10, 4)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('comment', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->decimal('trust_score', 4, 3)->default(0.5);
            $table->string('status', 16)->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason', 180)->nullable();
            $table->unsignedInteger('upvotes')->default(0);
            $table->unsignedInteger('downvotes')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['station_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('price_report_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('vote');
            $table->timestamp('created_at')->nullable();

            $table->unique(['price_report_id', 'user_id']);
        });

        Schema::create('ocr_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('gas_stations')->nullOnDelete();
            $table->string('image_path', 255);
            $table->text('raw_text')->nullable();
            $table->json('parsed_payload')->nullable();
            $table->string('engine', 24)->default('tesseract');
            $table->decimal('overall_confidence', 4, 3)->nullable();
            $table->string('status', 16)->default('processing');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });

        Schema::create('market_indicators', function (Blueprint $table): void {
            $table->id();
            $table->string('indicator', 32);
            $table->date('observed_on');
            $table->decimal('value', 14, 5);
            $table->string('unit', 16)->default('USD/bbl');
            $table->string('source', 48);
            $table->timestamps();

            $table->unique(['indicator', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indicators');
        Schema::dropIfExists('ocr_scans');
        Schema::dropIfExists('price_report_votes');
        Schema::dropIfExists('price_reports');
        Schema::dropIfExists('price_advisories');
        Schema::dropIfExists('fuel_price_history');
        Schema::dropIfExists('station_prices');
        Schema::dropIfExists('station_ratings');
        Schema::dropIfExists('station_photos');
        Schema::dropIfExists('station_hours');
        Schema::dropIfExists('station_payment_method');
        Schema::dropIfExists('station_amenity');
        Schema::dropIfExists('gas_stations');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('fuel_types');
    }
};
