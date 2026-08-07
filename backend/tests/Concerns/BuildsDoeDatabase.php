<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Doe\Models\DoePrice;
use App\Domain\Doe\Models\DoeStation;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the scraper's schema on a throwaway SQLite connection.
 *
 * There is no migration for these tables and there should not be: the Python
 * scraper owns them and creates them itself. Laravel reads them. So the tests
 * build the same shape by hand — which also means this file is the one place
 * that states, in the Laravel codebase, what the scraper's schema is. If the
 * two drift, these tests are where it shows.
 *
 * The connection is genuinely separate rather than a second set of tables on
 * the default one, because the real deployment is a separate database — the
 * platform already owns a `fuel_price_history` with a different shape, and a
 * test sharing one connection would not catch a query that forgot to say which.
 */
trait BuildsDoeDatabase
{
    protected function setUpDoeDatabase(): void
    {
        config()->set('database.connections.doe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $schema = Schema::connection('doe');

        $schema->dropIfExists('fuel_price_history');
        $schema->dropIfExists('fuel_stations');

        $schema->create('fuel_stations', function ($table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('company', 120);
            $table->string('name', 200)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('barangay', 120)->nullable();
            $table->string('address', 400)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });

        $schema->create('fuel_price_history', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('station_id');
            $table->date('price_date');
            foreach (DoePrice::FUELS as $fuel) {
                $table->decimal($fuel, 8, 4)->nullable();
            }
            $table->dateTime('scraped_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['station_id', 'price_date']);
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function doeStation(array $attributes = []): DoeStation
    {
        $attributes = array_merge([
            'company' => 'Petron',
            'name' => 'Petron EDSA',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Quezon City',
            'barangay' => 'Bagumbayan',
            'address' => '123 EDSA',
            'latitude' => 14.6510,
            'longitude' => 121.0490,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $attributes);

        $attributes['fingerprint'] ??= hash('sha256', implode('|', [
            strtolower((string) $attributes['company']),
            strtolower((string) $attributes['name']),
            strtolower((string) $attributes['city']),
            strtolower((string) ($attributes['barangay'] ?? '')),
        ]));

        return DoeStation::create($attributes);
    }

    /**
     * @param array<string, mixed> $prices
     */
    protected function doePrice(DoeStation $station, string $date, array $prices = []): DoePrice
    {
        return DoePrice::create(array_merge([
            'station_id' => $station->getKey(),
            'price_date' => $date,
            'ron91' => 58.20,
            'ron95' => 62.40,
            'diesel' => 56.85,
            'scraped_at' => now(),
        ], $prices));
    }
}
