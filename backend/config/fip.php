<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| Fuel Intelligence Platform — domain configuration
|---------------------------------------------------------------------------
| Business rules that operations may want to tune without a code change.
| Anything here can be overridden per-environment through .env, and the
| admin console persists overrides into the `settings` table which the
| SettingsRepository merges on top of these defaults at runtime.
*/

return [

    'pricing' => [
        // A crowd report from a user whose trust score is at or above this
        // value is published immediately instead of queuing for moderation.
        'crowd_auto_approve_trust' => (float) env('FIP_CROWD_AUTO_APPROVE_TRUST', 0.80),

        // Reject any submission deviating more than this fraction from the
        // prevailing city median for the same fuel type.
        'max_price_deviation_pct' => (float) env('FIP_MAX_PRICE_DEVIATION', 0.15),

        // A report must be geotagged within this radius of the station.
        'max_report_distance_m' => (int) env('FIP_MAX_REPORT_DISTANCE_M', 500),

        // Prices older than this are shown as "stale" in the UI.
        'stale_after_hours' => (int) env('FIP_PRICE_STALE_HOURS', 72),

        // Two matching independent reports promote a pending price to live.
        'corroborations_required' => (int) env('FIP_CORROBORATIONS', 2),

        'default_radius_km' => 5.0,
        'max_radius_km' => 50.0,
    ],

    'forecast' => [
        'horizon_weeks' => (int) env('FIP_FORECAST_HORIZON_WEEKS', 4),
        'min_confidence' => (float) env('FIP_MIN_FORECAST_CONFIDENCE', 0.60),
        // DOE adjustments take effect 06:00 every Tuesday.
        'effective_day_of_week' => 2,
        'effective_time' => '06:00',
        'cache_ttl' => 3600,
    ],

    'fraud' => [
        // Litres above tank capacity × (1 + tolerance) ⇒ overfill alert.
        'overfill_tolerance_pct' => (float) env('FIP_OVERFILL_TOLERANCE', 0.05),
        // Efficiency this many sigma from the vehicle baseline ⇒ alert.
        'efficiency_sigma_threshold' => (float) env('FIP_EFFICIENCY_SIGMA', 3.0),
        // Two fill-ups closer than this are suspicious.
        'min_minutes_between_fills' => (int) env('FIP_MIN_MINUTES_BETWEEN_FILLS', 30),
        'score_threshold' => (float) env('FIP_FRAUD_SCORE_THRESHOLD', 0.65),
        'severity_bands' => ['low' => 0.65, 'medium' => 0.75, 'high' => 0.85, 'critical' => 0.95],
    ],

    'fuel_level' => [
        // Tank level bands, as a percentage. A vehicle at or below `low_pct` is
        // LOW, at or below `critical_pct` is CRITICAL, and anything above is
        // NORMAL. Bands rather than a single warning point because "top up
        // today" and "you may not reach a station" are different instructions.
        'low_pct' => (float) env('FIP_FUEL_LOW_PCT', 25.0),
        'critical_pct' => (float) env('FIP_FUEL_CRITICAL_PCT', 10.0),

        // Beyond this, the stored level is reported as stale rather than
        // current. A reading is a sample, not a subscription: a tank that read
        // 80% last Tuesday tells you nothing about the tank today, and showing
        // it unqualified would be worse than showing nothing.
        'stale_after_minutes' => (int) env('FIP_FUEL_STALE_MINUTES', 720),
    ],

    'fuel_anomaly' => [
        // A fall of at least this many percentage points, inside this many
        // minutes, with no fill-up to explain it. The pair matters more than
        // either number: 40 points over a working day is a delivery round,
        // 40 points in a minute is not.
        'drop_pct' => (float) env('FIP_FUEL_DROP_PCT', 15.0),
        'drop_window_minutes' => (int) env('FIP_FUEL_DROP_WINDOW_MINUTES', 15),

        // Fuel appearing with no purchase behind it. Worth flagging for the
        // same reason as a drop: either the gauge is lying or the paperwork is.
        'gain_pct' => (float) env('FIP_FUEL_GAIN_PCT', 10.0),

        // A fall immediately undone by a comparable rise. No tank refills
        // itself, so the likeliest explanation is the sensor, not the fuel.
        'sensor_reversal_pct' => (float) env('FIP_FUEL_SENSOR_REVERSAL_PCT', 15.0),

        // How close a fill-up must be, in time, to explain a rise.
        'purchase_match_minutes' => (int) env('FIP_FUEL_PURCHASE_MATCH_MINUTES', 90),

        // Combined score at or above which an alert is raised. Separate from
        // the purchase-fraud threshold: these are different signals with
        // different false-positive costs, and tuning one must not move the other.
        'score_threshold' => (float) env('FIP_FUEL_ANOMALY_THRESHOLD', 0.65),
    ],

    'location' => [
        /*
         * How often a driver device samples its position, in seconds, while the
         * app is open. Served to the client rather than compiled into it, so
         * the cadence can be tuned without shipping a build.
         *
         * No product requirement in this repository states a frequency, so this
         * is a starting point rather than a promise: 120s is frequent enough to
         * follow a vehicle around a city and infrequent enough not to hold the
         * GPS radio awake. Revisit it against real battery data.
         */
        'sampling_interval_seconds' => (int) env('FIP_LOCATION_SAMPLING_SECONDS', 120),

        // Skip a sample when the device has barely moved. A vehicle parked for
        // an hour should cost one row, not thirty identical ones.
        'minimum_distance_metres' => (int) env('FIP_LOCATION_MIN_DISTANCE_M', 50),

        // Readings less accurate than this are rejected: a 2 km "fix" from a
        // cell tower is worse than no position, because it looks like a position.
        'max_accuracy_metres' => (int) env('FIP_LOCATION_MAX_ACCURACY_M', 1000),

        // How far a device clock may run ahead of the server before a reading
        // is refused. Phones drift; time machines do not exist.
        'max_clock_skew_minutes' => (int) env('FIP_LOCATION_MAX_SKEW_MINUTES', 5),

        // Largest batch one flush may carry, so an offline queue cannot arrive
        // as a single unbounded insert.
        'max_batch_size' => (int) env('FIP_LOCATION_MAX_BATCH', 200),

        // Widest window a history query may request, and the most rows it may
        // return. Unbounded history over a fleet is both a performance problem
        // and a surveillance one.
        'max_history_days' => (int) env('FIP_LOCATION_MAX_HISTORY_DAYS', 31),
        'max_history_rows' => (int) env('FIP_LOCATION_MAX_HISTORY_ROWS', 5_000),

        /*
         * ⚠️ PROVISIONAL — REQUIRES BUSINESS AND PRIVACY APPROVAL.
         *
         * No approved retention period exists for a movement track. The nearest
         * precedents in docs/07-security.md are 90 days for report geotags and
         * 30 days for generated reports, and neither is a record of where an
         * identifiable person has been.
         *
         * 30 is the shortest existing retention in this file, chosen so the
         * default errs towards deleting sooner rather than keeping longer. It
         * is deliberately not presented as the right answer. Set
         * FIP_LOCATION_RETENTION_DAYS once the business has decided, and update
         * the personal-data table in docs/07-security.md at the same time.
         */
        'retention_days' => (int) env('FIP_LOCATION_RETENTION_DAYS', 30),

        /*
         * Upper bound on what an administrator may set through the admin UI.
         * A year is already a long time to hold a movement track; anything
         * beyond it should require a conversation, not a form field.
         */
        'retention_max_days' => (int) env('FIP_LOCATION_RETENTION_MAX_DAYS', 365),
    ],

    'device_health' => [
        /*
         * How long after its last contact a device is called offline.
         *
         * Derived from the sampling interval rather than picked freely: a
         * device reports roughly every `location.sampling_interval_seconds`,
         * so anything under a couple of intervals would flag a device that
         * merely missed one fix in a car park. Three intervals is 6 minutes
         * at the current cadence; 15 gives room for a tunnel, a lift, and a
         * queue flushed late without crying wolf.
         */
        'offline_after_minutes' => (int) env('FIP_DEVICE_OFFLINE_MINUTES', 15),

        /*
         * Past this, a battery reading is history rather than status. A device
         * that stopped reporting still holds the last percentage it sent, and
         * showing that as current would send someone looking for a van whose
         * phone is simply flat. Longer than the offline window on purpose: a
         * recently-offline device's last reading is still the most useful
         * thing anyone can say about it.
         */
        'battery_stale_after_minutes' => (int) env('FIP_DEVICE_BATTERY_STALE_MINUTES', 60),

        /*
         * The charge at which a tracking device is worth someone's attention.
         * A phone below this will likely stop reporting before a shift ends,
         * and a vehicle whose device dies is a vehicle nobody can see.
         */
        'low_battery_pct' => (int) env('FIP_DEVICE_LOW_BATTERY_PCT', 20),

        /*
         * Past this, a position is where a vehicle was rather than where it is.
         *
         * Longer than the offline window on purpose, and for a different
         * reason than the battery one: a phone reports its presence far more
         * often than it moves, and the sampling filter deliberately suppresses
         * fixes for a vehicle that is standing still. A fifteen-minute gap in
         * positions is a parked van, not a fault.
         */
        'location_stale_after_minutes' => (int) env('FIP_DEVICE_LOCATION_STALE_MINUTES', 30),

        /*
         * How long a silent web registration is kept.
         *
         * Signing in on the web writes a device row, and the browser's
         * identifier lives in localStorage — so a cleared profile, a private
         * window or a second browser each mint one that nothing ever removes.
         * They are session identities rather than devices: they cannot be
         * attached to a vehicle and cannot report a position.
         *
         * 90 days is long enough that a browser used occasionally is never
         * disturbed, and it only ever removes a registration that has been
         * silent for a quarter. Set to 0 to keep them forever.
         *
         * Handsets are never pruned by this, however quiet they go. A phone
         * carries the history the fleet is measured on, and a vehicle's track
         * must not disappear because a driver was on leave.
         */
        'web_registration_retention_days' => (int) env('FIP_WEB_DEVICE_RETENTION_DAYS', 90),
    ],

    /*
     * Subscription capacity.
     *
     * ⚠️ PROVISIONAL — REQUIRES BUSINESS APPROVAL.
     *
     * No approved plan exists in this repository: `companies.subscription_tier`
     * has held free/business/enterprise since the first migration and nothing
     * has ever read it. The numbers below are placeholders chosen so no
     * existing company is already over a limit; they are not a price list, and
     * they are deliberately configuration rather than code so the business can
     * set them without a deploy.
     *
     * A null limit means unlimited. An unknown or missing tier falls back to
     * `free`, which is the safest direction: a misconfigured tenant gets the
     * smallest allowance rather than an unbounded one.
     *
     * Limits are checked only when something new is created. Nothing existing
     * is ever removed, hidden or made read-only by a tier — a driver must not
     * lose access to their vehicle mid-shift because of a billing state.
     */
    'subscription' => [
        'default_tier' => env('FIP_DEFAULT_TIER', 'free'),

        'tiers' => [
            'free' => [
                'vehicles' => (int) env('FIP_TIER_FREE_VEHICLES', 3),
                'seats' => (int) env('FIP_TIER_FREE_SEATS', 2),
                'devices' => (int) env('FIP_TIER_FREE_DEVICES', 3),
            ],
            'business' => [
                'vehicles' => (int) env('FIP_TIER_BUSINESS_VEHICLES', 25),
                'seats' => (int) env('FIP_TIER_BUSINESS_SEATS', 15),
                'devices' => (int) env('FIP_TIER_BUSINESS_DEVICES', 30),
            ],
            'enterprise' => [
                'vehicles' => null,
                'seats' => null,
                'devices' => null,
            ],
        ],
    ],

    'maintenance' => [
        'due_soon_days' => (int) env('FIP_MAINTENANCE_DUE_SOON_DAYS', 14),
        'due_soon_km' => (int) env('FIP_MAINTENANCE_DUE_SOON_KM', 500),
        'document_reminder_days' => [60, 30, 14, 7, 1],
    ],

    'expenses' => [
        // Fill-ups older than this cannot be edited by a driver.
        'edit_window_hours' => (int) env('FIP_EXPENSE_EDIT_WINDOW', 48),
        'currency' => 'PHP',
        'currency_symbol' => '₱',
    ],

    'ocr' => [
        'min_confidence' => (float) env('FIP_OCR_MIN_CONFIDENCE', 0.75),
        'auto_submit_confidence' => (float) env('FIP_OCR_AUTO_SUBMIT_CONFIDENCE', 0.90),
        'max_image_mb' => 8,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
    ],

    'routing' => [
        'max_waypoints' => 8,
        'max_detour_km' => 10.0,
        'alternatives' => 3,
    ],

    'reports' => [
        'max_rows_sync' => 5_000,   // beyond this the export is queued
        'retention_days' => 30,
        'formats' => ['pdf', 'xlsx', 'csv', 'json'],
    ],

    'rate_limits' => [
        'auth' => env('FIP_RL_AUTH', '10,1'),          // requests, minutes
        'public' => env('FIP_RL_PUBLIC', '60,1'),
        'authenticated' => env('FIP_RL_AUTH_USER', '120,1'),
        'ai' => env('FIP_RL_AI', '20,1'),
        'ocr' => env('FIP_RL_OCR', '10,1'),
        // Generous per minute because a device flushes a queue in batches after
        // signal returns, but still bounded so a compromised token cannot
        // firehose the location table.
        'location' => env('FIP_RL_LOCATION', '60,1'),
        'reports' => env('FIP_RL_REPORTS', '10,5'),
    ],

    'security' => [
        'max_failed_logins' => (int) env('FIP_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('FIP_LOCKOUT_MINUTES', 15),
        'password_min_length' => 12,
        'mfa_window' => 1,
        'audit_retention_days' => 365,
    ],

    'roles' => [
        'super_admin' => 'super_admin',
        'system_admin' => 'system_admin',
        'station_admin' => 'station_admin',
        'fleet_manager' => 'fleet_manager',
        'company_manager' => 'company_manager',
        'driver' => 'driver',
        'viewer' => 'viewer',
        'user' => 'user',
        'guest' => 'guest',
    ],
];
