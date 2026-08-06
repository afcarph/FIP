<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a staging environment needs and nothing it should not have.
 *
 * Deliberately excludes DemoDataSeeder. That seeder creates users with
 * passwords published in the repository, which is fine on a laptop and not on a
 * box other people can reach. Testers register their own accounts; the point of
 * this seeder is that there is something to look at once they do.
 *
 *   php artisan db:seed --class=StagingSeeder
 */
class StagingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            RolePermissionSeeder::class,
            GasStationSeeder::class,
            StationPriceSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Staging data seeded. Next:');
        $this->command->line('  php artisan fip:forecast     # forecasts from the seeded history');
        $this->command->line('  php artisan fip:import-doe   # move prices on by a week');
    }
}
