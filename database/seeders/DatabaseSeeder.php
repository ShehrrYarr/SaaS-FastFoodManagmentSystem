<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /** A public demo (`sfms:demo-reset`) keeps its own Super Admins instead of the sample one. */
    public static bool $withSampleSuperAdmin = true;

    /**
     * Sample logins are listed in README.md. All seeded accounts use the
     * password "password" and are for local development only.
     */
    public function run(): void
    {
        if (static::$withSampleSuperAdmin) {
            $this->call(SuperAdminSeeder::class);
        }

        $this->call(SampleShopsSeeder::class);

        // The sample shops' menus, accounts and opening stock are dated the day before
        // their sales history starts, so every ledger reads in time order.
        Carbon::setTestNow(Carbon::now()->subDays(SampleSalesSeeder::$historyDays + 1)->setTime(9, 0));

        try {
            $this->call([
                SampleShopDataSeeder::class,
                SampleInventorySeeder::class,
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $this->call(SampleSalesSeeder::class);
    }
}
