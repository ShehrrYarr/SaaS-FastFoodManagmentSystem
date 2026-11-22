<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something changed in this shop": sent to the shop's private channel so
 * open screens (tabs board, POS, dashboard...) reload their own data.
 * Carries only which areas changed and which orders, never the details.
 */
class ShopUpdated implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $topics  orders | day | money | stock
     * @param  list<int>  $orders  ids of orders that changed
     */
    public function __construct(
        public int $shopId,
        public array $topics,
        public array $orders = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('shop.'.$this->shopId)];
    }

    public function broadcastAs(): string
    {
        return 'shop.updated';
    }

    public function broadcastWith(): array
    {
        return ['topics' => $this->topics, 'orders' => $this->orders];
    }
}
