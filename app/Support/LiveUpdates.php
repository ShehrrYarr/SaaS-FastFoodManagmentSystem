<?php

namespace App\Support;

use App\Events\ShopUpdated;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects what changed during one request (or command) and sends a single
 * ShopUpdated per shop when it finishes, so a checkout that writes an order,
 * two payments and three ledger lines still sends one message.
 *
 * Sending happens after the response, outside any transaction. A rolled-back
 * change may still send a hint; screens simply reload and see nothing new.
 * If Reverb is down the error is logged and the sale is unaffected.
 */
final class LiveUpdates
{
    public const TOPICS = ['orders', 'day', 'money', 'stock'];

    private const MAX_ORDER_IDS = 50;

    /** @var array<int, array{topics: array<string, true>, orders: array<int, true>}> */
    private array $pending = [];

    public function touch(?int $shopId, string $topic, ?int $orderId = null): void
    {
        if (! $shopId || ! in_array($topic, self::TOPICS, true)) {
            return;
        }

        $this->pending[$shopId]['topics'][$topic] = true;
        $this->pending[$shopId]['orders'] ??= [];

        if ($orderId) {
            $this->pending[$shopId]['orders'][$orderId] = true;
        }
    }

    /** @return array<int, array{topics: list<string>, orders: list<int>}> */
    public function pending(): array
    {
        return array_map(fn (array $p) => [
            'topics' => array_keys($p['topics']),
            'orders' => array_slice(array_keys($p['orders']), 0, self::MAX_ORDER_IDS),
        ], $this->pending);
    }

    public function flush(): void
    {
        $pending = $this->pending();
        $this->pending = [];

        foreach ($pending as $shopId => $change) {
            try {
                event(new ShopUpdated($shopId, $change['topics'], $change['orders']));
            } catch (Throwable $e) {
                Log::warning('Live update not sent (is Reverb running?): '.$e->getMessage());
            }
        }
    }
}
