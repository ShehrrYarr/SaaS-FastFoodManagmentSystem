<?php

namespace App\Actions\Shops;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Onboards a shop in one transaction: the shop, its default roles
 * (Owner / Manager / Cashier), the Cash Drawer, the default expense
 * categories and the owner's login.
 */
class CreateShop
{
    public function __construct(private TenantContext $tenancy) {}

    /**
     * @param  array<string, mixed>  $shopData
     * @param  array{name: string, email?: ?string, username?: ?string, phone?: ?string, password: string}  $ownerData
     */
    public function handle(array $shopData, array $ownerData): Shop
    {
        return DB::transaction(function () use ($shopData, $ownerData) {
            $shop = Shop::create($shopData);

            $this->tenancy->run($shop, function (Shop $shop) use ($ownerData) {
                $roles = $this->createDefaultRoles();

                Account::create([
                    'name' => 'Cash Drawer',
                    'type' => 'cash',
                    'is_default_cash' => true,
                ]);

                ExpenseCategory::createDefaults();

                User::create([
                    'tenant_id' => $shop->id,
                    'role_id' => $roles['Owner']->id,
                    'name' => $ownerData['name'],
                    'email' => $ownerData['email'] ?? null,
                    'username' => $ownerData['username'] ?? null,
                    'phone' => $ownerData['phone'] ?? null,
                    'password' => $ownerData['password'],
                    'is_active' => true,
                ]);
            });

            return $shop;
        });
    }

    /** @return array<string, Role> */
    public function createDefaultRoles(): array
    {
        $roles = [];

        foreach (Permissions::DEFAULT_ROLES as $name => $definition) {
            $roles[$name] = Role::create([
                'name' => $name,
                'description' => $definition['description'],
                'permissions' => Permissions::forDefaultRole($name),
                'is_locked' => $definition['locked'],
            ]);
        }

        return $roles;
    }
}
