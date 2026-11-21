<?php

namespace App\Tenancy;

use App\Models\Shop;

/**
 * Holds the shop (tenant) for the current request.
 *
 * Set by ResolveShopByPrefix for every /{shop}/... request (including
 * Livewire updates, via persistent middleware). Every model using
 * BelongsToTenant is scoped to this shop; with no shop set, those
 * queries return nothing (fail closed).
 */
class TenantContext
{
    private ?Shop $shop = null;

    public function set(Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function forget(): void
    {
        $this->shop = null;
    }

    public function shop(): ?Shop
    {
        return $this->shop;
    }

    public function id(): ?int
    {
        return $this->shop?->getKey();
    }

    public function check(): bool
    {
        return $this->shop !== null;
    }

    /**
     * Run a callback as the given shop, restoring the previous shop afterwards.
     * Used by seeders, Super Admin actions and queued jobs.
     *
     * @template T
     *
     * @param  callable(Shop): T  $callback
     * @return T
     */
    public function run(Shop $shop, callable $callback): mixed
    {
        $previous = $this->shop;
        $this->shop = $shop;

        try {
            return $callback($shop);
        } finally {
            $this->shop = $previous;
        }
    }
}
