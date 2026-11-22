<?php

/*
 * Worker for StockConcurrencyTest: boots the app against the test database and
 * sends N takeaway orders for one menu item, as fast as it can.
 *
 *   php tests/Concurrency/send-orders.php <shopId> <userId> <variantId> <orders>
 *
 * Prints "ok <n>" or "fail <message>".
 */

use App\Models\Shop;
use App\Models\User;
use App\Services\Orders;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $shopId, $userId, $variantId, $count] = $argv;

try {
    app(TenantContext::class)->set(Shop::query()->findOrFail((int) $shopId));
    Auth::guard('shop')->setUser(User::query()->findOrFail((int) $userId));

    for ($i = 0; $i < (int) $count; $i++) {
        app(Orders::class)->send(null, ['type' => 'takeaway'], [['variant_id' => (int) $variantId, 'quantity' => 1]]);
    }

    echo 'ok '.$count;
} catch (Throwable $e) {
    echo 'fail '.get_class($e).': '.$e->getMessage();
    exit(1);
}
