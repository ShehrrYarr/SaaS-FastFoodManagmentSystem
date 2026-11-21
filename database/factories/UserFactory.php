<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_super_admin' => false,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['tenant_id' => null, 'role_id' => null, 'is_super_admin' => true]);
    }

    /** A staff user of the shop with the given default role (Owner / Manager / Cashier). */
    public function forShop(Shop $shop, string $roleName = 'Cashier'): static
    {
        return $this->state(function () use ($shop, $roleName) {
            $role = Role::query()->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $shop->id)
                ->where('name', $roleName)
                ->first();

            return ['tenant_id' => $shop->id, 'role_id' => $role?->id, 'is_super_admin' => false];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
