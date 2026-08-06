<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Order matters: reference data first, then authorisation, then the dataset the
 * environment asked for.
 *
 * The default seeds the demo dataset, which creates accounts with known
 * passwords — right for a laptop, wrong for a shared box. Staging uses
 * StagingSeeder instead: stations, prices and history, and no accounts at all,
 * so testers register themselves and nothing ships with a published credential.
 *
 *   php artisan db:seed                              # local, with demo accounts
 *   php artisan db:seed --class=StagingSeeder        # staging, data only
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            RolePermissionSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
