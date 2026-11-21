<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@sfms.test', 'tenant_id' => null],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'is_super_admin' => true,
                'is_active' => true,
            ],
        );
    }
}
