<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Order matters: reference data first, then authorisation, then the demo
 * dataset which depends on both.
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
