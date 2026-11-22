<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Sample logins are listed in README.md. All seeded accounts use the
     * password "password" and are for local development only.
     */
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            SampleShopsSeeder::class,
            SampleShopDataSeeder::class,
            SampleInventorySeeder::class,
        ]);
    }
}
