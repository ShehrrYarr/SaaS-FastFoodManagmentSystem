<?php

namespace Tests\Concerns;

use App\Actions\Shops\CreateShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Carbon;

trait CreatesShops
{
    protected function makeShop(string $prefix, array $attributes = []): Shop
    {
        return app(CreateShop::class)->handle(
            array_merge([
                'name' => strtoupper($prefix).' Shop',
                'prefix' => $prefix,
                'order_prefix' => strtoupper(str_replace('-', '', $prefix)),
                'subscription_ends_at' => Carbon::now(Shop::DEFAULT_TIMEZONE)->addMonth()->toDateString(),
            ], $attributes),
            ['name' => 'Owner '.$prefix, 'email' => "owner@{$prefix}.test", 'password' => 'password'],
        );
    }

    protected function ownerOf(Shop $shop): User
    {
        return User::ofShop($shop)->oldest('id')->firstOrFail();
    }
}
