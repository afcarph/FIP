<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a subscription somewhere to live.
 *
 * `companies.subscription_tier` has carried the entire subscription since the
 * first migration: which plan, and nothing else. A client registering for a
 * trial needs more than a plan name — when the trial started, when it ends,
 * and whether it is still running — and an enterprise client needs limits that
 * were negotiated rather than read from a config file meant for everybody.
 *
 * Columns on `companies` rather than a `subscriptions` table. There is one
 * subscription per company, no history to keep, no billing periods and no
 * invoices; a separate table would add a join to every limit check and a
 * second source of truth for the plan. If billing ever arrives with real
 * periods and payments, that is the point to split it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Where the subscription is in its life, as distinct from which
            // plan it is on. A company can be on `business` and expired, or on
            // `enterprise` and not yet confirmed by an administrator.
            $table->string('subscription_status', 24)->default('active')->after('subscription_tier');

            $table->timestamp('trial_started_at')->nullable()->after('subscription_status');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');

            /*
             * Negotiated limits, for enterprise agreements that are not what
             * the config file says. Null means "use the plan's configured
             * limits", which is every ordinary company. A JSON column rather
             * than three more integer columns because the set of limited
             * resources is config-driven and has already grown once.
             */
            $table->json('subscription_limits')->nullable()->after('trial_ends_at');

            // Read together on every capacity check and on the admin listing.
            $table->index(['subscription_tier', 'subscription_status']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['subscription_tier', 'subscription_status']);
            $table->dropColumn([
                'subscription_status',
                'trial_started_at',
                'trial_ends_at',
                'subscription_limits',
            ]);
        });
    }
};
