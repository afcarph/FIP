<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geography reference data, tenants, identity and RBAC.
 *
 * The canonical DDL lives in `database/schema.sql`; these migrations express
 * the same structure through the schema builder so that CI, local SQLite runs
 * and production MySQL all converge on one shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->string('island_group', 16)->default('luzon');
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->timestamps();

            $table->index('region_id');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->boolean('is_city')->default(true);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['province_id', 'name']);
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('legal_name', 180)->nullable();
            $table->string('tin', 32)->nullable();
            $table->string('industry', 80)->nullable();
            $table->string('type', 24)->default('logistics');
            $table->string('address_line', 255)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_email', 180)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('subscription_tier', 24)->default('free');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'deleted_at']);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 180)->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password')->nullable();          // null for OAuth-only accounts
            $table->string('avatar_path', 255)->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 48)->default('Asia/Manila');
            $table->foreignId('home_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->binary('mfa_secret')->nullable();         // AES-256 ciphertext
            $table->binary('mfa_recovery_codes')->nullable();
            $table->boolean('biometric_enabled')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->binary('last_login_ip')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'deleted_at']);
        });

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('theme', 12)->default('system');
            $table->unsignedBigInteger('preferred_fuel_type_id')->nullable();
            $table->decimal('price_alert_threshold', 6, 2)->nullable();
            $table->decimal('alert_radius_km', 5, 2)->default(5.00);
            $table->boolean('notify_price_alerts')->default(true);
            $table->boolean('notify_maintenance')->default(true);
            $table->boolean('notify_ai_insights')->default(true);
            $table->boolean('notify_marketing')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamps();
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid', 64);
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 16)->default('web');
            $table->string('fcm_token', 255)->nullable();
            $table->text('biometric_key')->nullable();        // device public key
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::create('oauth_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('provider_uid', 191);
            $table->string('email', 180)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_uid']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 180)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 180);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->binary('ip_address');
            $table->string('user_agent', 255)->nullable();
            $table->boolean('succeeded')->default(false);
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['email', 'attempted_at']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 48)->default('general');
            $table->string('key', 96);
            $table->json('value')->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('event', 48);
            $table->string('auditable_type', 96);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('url', 255)->nullable();
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['path', 'created_at']);
            $table->index('status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('oauth_accounts');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('regions');
    }
};
