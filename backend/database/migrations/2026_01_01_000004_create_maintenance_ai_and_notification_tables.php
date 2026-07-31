<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Maintenance, AI artefacts, notifications and reporting. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('category', 24)->default('preventive');
            $table->unsignedInteger('default_interval_km')->nullable();
            $table->unsignedInteger('default_interval_days')->nullable();
            $table->string('icon', 48)->nullable();
            $table->timestamps();
        });

        Schema::create('maintenance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_type_id')->constrained();
            $table->date('performed_at');
            $table->decimal('odometer', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('vendor', 180)->nullable();
            $table->string('invoice_path', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'performed_at']);
        });

        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_type_id')->constrained();
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->date('last_performed_at')->nullable();
            $table->decimal('last_odometer', 12, 2)->nullable();
            $table->date('due_at')->nullable();
            $table->decimal('due_odometer', 12, 2)->nullable();
            $table->date('predicted_due_at')->nullable();
            $table->decimal('prediction_confidence', 4, 3)->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vehicle_id', 'maintenance_type_id', 'deleted_at'], 'uq_schedule_vehicle_type');
            $table->index(['due_at', 'status']);
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48);
            $table->string('name', 120);
            $table->string('algorithm', 48);
            $table->string('version', 24);
            $table->string('artefact_path', 255)->nullable();
            $table->json('hyperparameters')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->unsignedInteger('training_rows')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['code', 'is_active']);
        });

        Schema::create('price_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('fuel_type_id')->constrained();
            $table->foreignId('region_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('forecast_for');
            $table->timestamp('generated_at');
            $table->string('direction', 8);
            $table->decimal('change_amount', 10, 4);
            $table->decimal('predicted_price', 10, 4)->nullable();
            $table->decimal('lower_bound', 10, 4)->nullable();
            $table->decimal('upper_bound', 10, 4)->nullable();
            $table->decimal('confidence', 4, 3);
            $table->json('drivers')->nullable();
            $table->string('narrative', 1000)->nullable();
            $table->decimal('actual_change', 10, 4)->nullable();
            $table->decimal('absolute_error', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['fuel_type_id', 'region_id', 'forecast_for', 'generated_at'], 'uq_price_forecasts');
            $table->index('forecast_for');
        });

        Schema::create('ai_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('prediction_type', 48);
            $table->unsignedSmallInteger('horizon_days')->nullable();
            $table->json('payload');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'prediction_type'], 'idx_ai_predictions_subject');
        });

        Schema::create('ai_chat_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180)->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('token_usage')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_chat_sessions')->cascadeOnDelete();
            $table->string('role', 12);
            $table->mediumText('content');
            $table->json('tool_calls')->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['session_id', 'created_at']);
        });

        Schema::create('fraud_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fuel_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('alert_type', 32);
            $table->string('severity', 12)->default('medium');
            $table->decimal('score', 4, 3);
            $table->json('evidence')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('detected_at');
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48);
            $table->string('channel', 16)->default('push');
            $table->string('title', 180);
            $table->string('body', 500);
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'channel']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 96);
            $table->string('category', 32)->default('general');
            $table->string('title', 180);
            $table->string('body', 500);
            $table->json('data')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->string('priority', 12)->default('normal');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->constrained();
            $table->foreignId('station_id')->nullable()->constrained('gas_stations')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('condition', 16)->default('below');
            $table->decimal('threshold', 10, 4);
            $table->decimal('radius_km', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index(['fuel_type_id', 'is_active']);
        });

        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name', 180);
            $table->string('description', 500)->nullable();
            $table->string('scope', 24)->default('user');
            $table->json('default_params')->nullable();
            $table->string('required_permission', 96)->nullable();
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_definition_id')->constrained();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('params')->nullable();
            $table->string('format', 8)->default('pdf');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'created_at']);
            $table->index('status');
        });

        Schema::create('jwt_blacklist', function (Blueprint $table): void {
            $table->id();
            $table->string('jti', 64)->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jwt_blacklist');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('price_alerts');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
        Schema::dropIfExists('ai_predictions');
        Schema::dropIfExists('price_forecasts');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('maintenance_types');
    }
};
