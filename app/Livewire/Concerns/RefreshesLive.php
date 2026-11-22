<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * Re-renders a screen when something it shows changes on another till.
 *
 * sfms-live.js turns each ShopUpdated broadcast into a 'shop-updated'
 * Livewire event with the areas that changed (orders, day, money, stock) and
 * the order ids. Screens say which areas they show with liveTopics(); a
 * re-render reloads their computed data through the usual checks. Override
 * refreshLive() to react to particular orders.
 */
trait RefreshesLive
{
    /** @return list<string> */
    protected function liveTopics(): array
    {
        return ['orders', 'day'];
    }

    /**
     * @param  list<string>  $topics
     * @param  list<int>  $orders
     */
    #[On('shop-updated')]
    public function onShopUpdated(array $topics = [], array $orders = []): void
    {
        if (array_intersect($topics, $this->liveTopics()) === []) {
            $this->skipRender();

            return;
        }

        $this->refreshLive($topics, array_map('intval', $orders));
    }

    /**
     * @param  list<string>  $topics
     * @param  list<int>  $orders
     */
    protected function refreshLive(array $topics, array $orders): void
    {
        // Computed properties are rebuilt on the re-render that follows.
    }
}
