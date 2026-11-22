<?php

namespace Database\Seeders;

use App\Actions\Shops\CreateShop;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Two working sample shops plus one suspended shop for testing access rules.
 *  - Burger Lab Gulberg  /blg  inventory tracking ON  (burger recipe example arrives with M4)
 *  - Pizza Point Johar Town  /ppj  inventory OFF, subscription ending in 5 days
 *  - Shawarma Stop DHA  /ssd  suspended
 */
class SampleShopsSeeder extends Seeder
{
    /**
     * Password for every sample login (documented in the README). A public demo
     * (`sfms:demo-reset`) sets a random one per login, so the README logins never work there.
     */
    public static ?string $password = 'password';

    public function run(CreateShop $createShop, TenantContext $tenancy): void
    {
        $today = Carbon::now(Shop::DEFAULT_TIMEZONE)->startOfDay();

        $shops = [
            [
                'shop' => [
                    'name' => 'Burger Lab Gulberg',
                    'prefix' => 'blg',
                    'order_prefix' => 'BLG',
                    'phone' => '042-35710001',
                    'address' => 'Main Boulevard, Gulberg III, Lahore',
                    'subscription_ends_at' => $today->copy()->addMonths(6)->toDateString(),
                    'inventory_tracking_enabled' => true,
                    'service_charge_value' => 5,
                    'default_delivery_charge' => 150,
                ],
                'owner' => ['name' => 'Hamza Riaz', 'email' => 'owner@blg.test', 'username' => 'hamza', 'phone' => '0300-1234567'],
                'staff' => [
                    ['name' => 'Ayesha Khan', 'email' => 'manager@blg.test', 'username' => 'ayesha', 'role' => 'Manager'],
                    ['name' => 'Bilal Ahmed', 'email' => 'cashier@blg.test', 'username' => 'bilal', 'role' => 'Cashier'],
                ],
            ],
            [
                'shop' => [
                    'name' => 'Pizza Point Johar Town',
                    'prefix' => 'ppj',
                    'order_prefix' => 'PPJ',
                    'phone' => '042-35220002',
                    'address' => 'Block G1, Johar Town, Lahore',
                    'subscription_ends_at' => $today->copy()->addDays(5)->toDateString(),
                    'inventory_tracking_enabled' => false,
                    'default_delivery_charge' => 100,
                ],
                'owner' => ['name' => 'Sana Malik', 'email' => 'owner@ppj.test', 'username' => 'sana', 'phone' => '0321-7654321'],
                'staff' => [
                    ['name' => 'Usman Tariq', 'email' => 'cashier@ppj.test', 'username' => 'usman', 'role' => 'Cashier'],
                ],
            ],
            [
                'shop' => [
                    'name' => 'Shawarma Stop DHA',
                    'prefix' => 'ssd',
                    'order_prefix' => 'SSD',
                    'phone' => '042-35890003',
                    'address' => 'Phase 5, DHA, Lahore',
                    'subscription_ends_at' => $today->copy()->addMonth()->toDateString(),
                    'is_active' => false,
                    'suspended_reason' => 'Payment overdue',
                    'suspended_at' => now(),
                ],
                'owner' => ['name' => 'Ali Raza', 'email' => 'owner@ssd.test', 'username' => 'ali'],
                'staff' => [],
            ],
        ];

        foreach ($shops as $data) {
            if (Shop::query()->where('prefix', $data['shop']['prefix'])->exists()) {
                continue;
            }

            $shop = $createShop->handle($data['shop'], $data['owner'] + ['password' => static::password()]);

            $tenancy->run($shop, function (Shop $shop) use ($data) {
                foreach ($data['staff'] as $staff) {
                    User::create([
                        'tenant_id' => $shop->id,
                        'role_id' => Role::query()->where('name', $staff['role'])->value('id'),
                        'name' => $staff['name'],
                        'email' => $staff['email'],
                        'username' => $staff['username'],
                        'password' => static::password(),
                    ]);
                }
            });
        }
    }

    private static function password(): string
    {
        return static::$password ?? \Illuminate\Support\Str::random(40);
    }
}
