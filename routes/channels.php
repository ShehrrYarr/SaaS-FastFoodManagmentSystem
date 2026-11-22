<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| One private channel per shop. Only active staff of that shop, while the
| shop itself is accessible (not suspended or expired), may listen.
| The channel carries "something changed" hints, never order details: each
| screen reloads its own data through the normal permission-checked code.
|
*/

Broadcast::channel('shop.{shopId}', function (User $user, int $shopId) {
    return (int) $user->tenant_id === $shopId
        && $user->is_active
        && (bool) $user->shop?->isAccessible();
}, ['guards' => ['shop']]);
